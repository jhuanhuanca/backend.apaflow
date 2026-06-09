<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\SaaS\SubscriptionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessDocumentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(public Document $document) {}

    public function handle(SubscriptionService $subscriptions): void
    {
        $document = $this->document->fresh(['user']);
        if (! $document) {
            return;
        }

        $document->update(['status' => Document::STATUS_PROCESSING]);
        $document->addLog('Estado: procesando. Enviando archivo al motor APA (Python FastAPI).');

        $disk = Storage::disk('local');
        if (! $disk->exists($document->original_file)) {
            $this->failDocument($document, 'No se encontró el archivo original en storage.');

            return;
        }

        $absolute = $disk->path($document->original_file);
        $filename = basename($absolute);

        $url = rtrim(config('services.python.url'), '/').'/process-document';
        $timeout = (int) config('services.python.timeout', 300);

        $apaSettings = $document->user
            ? $subscriptions->resolvedApaSettings($document->user)
            : $subscriptions->defaultApaSettings();

        try {
            $request = Http::timeout($timeout)
                ->accept('application/vnd.openxmlformats-officedocument.wordprocessingml.document')
                ->attach('file', file_get_contents($absolute), $filename)
                ->attach(
                    'apa_settings',
                    json_encode($apaSettings, JSON_UNESCAPED_UNICODE),
                    'settings.json',
                    ['Content-Type' => 'application/json'],
                );

            $apiKey = config('services.python.api_key');
            if (is_string($apiKey) && $apiKey !== '') {
                $request = $request->withHeaders([
                    'X-Internal-Api-Key' => $apiKey,
                ]);
            }

            $response = $request->post($url);

            if (! $response->successful()) {
                $body = $response->body();
                Log::warning('Python APA devolvió error HTTP', [
                    'document_id' => $document->id,
                    'status' => $response->status(),
                    'body' => mb_substr($body, 0, 2000),
                ]);
                $this->failDocument($document, 'El motor Python respondió con error HTTP '.$response->status().'.');

                return;
            }

            $dir = $document->user_id !== null
                ? (string) $document->user_id
                : ($document->guest_fingerprint
                    ? 'guest/'.substr($document->guest_fingerprint, 0, 16)
                    : 'guest/unknown');
            $processedRelative = 'documents/'.$dir.'/processed_'.$document->id.'_'.$filename;
            $disk->put($processedRelative, $response->body());

            $document->update([
                'processed_file' => $processedRelative,
                'status' => Document::STATUS_COMPLETED,
            ]);
            $reportHeader = $response->header('X-Apa-Report');
            if ($reportHeader !== '') {
                $document->addLog('Informe APA (base64): '.mb_substr($reportHeader, 0, 120).'…');
            }
            $document->addLog('Procesamiento completado. Archivo APA 7 generado y almacenado.');
        } catch (Throwable $e) {
            Log::error('Fallo ProcessDocumentJob', [
                'document_id' => $document->id,
                'exception' => $e->getMessage(),
            ]);
            $this->failDocument($document, 'Excepción al contactar Python: '.$e->getMessage());
        }
    }

    public function failed(?Throwable $exception): void
    {
        $document = $this->document->fresh();
        if ($document && $document->status !== Document::STATUS_COMPLETED) {
            $msg = $exception ? $exception->getMessage() : 'Job fallido sin excepción.';
            $this->failDocument($document, 'Job en cola fallido: '.$msg);
        }
    }

    private function failDocument(Document $document, string $message): void
    {
        $document->addLog($message);
        $document->update(['status' => Document::STATUS_FAILED]);
    }
}
