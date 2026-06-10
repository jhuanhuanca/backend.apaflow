<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\SaaS\SubscriptionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\Response;
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

        if (! $this->assertPythonReachable($document)) {
            return;
        }

        $absolute = $disk->path($document->original_file);
        $filename = basename($absolute);

        $apaSettings = $document->user
            ? $subscriptions->resolvedApaSettings($document->user)
            : $subscriptions->defaultApaSettings();

        try {
            $response = $this->callPythonProcessor($absolute, $filename, $apaSettings);

            if (! $response->successful()) {
                $body = mb_substr($response->body(), 0, 800);
                Log::warning('Python APA devolvió error HTTP', [
                    'document_id' => $document->id,
                    'status' => $response->status(),
                    'body' => $body,
                ]);
                $detail = $body !== '' ? " Detalle: {$body}" : '';
                $this->failDocument(
                    $document,
                    'El motor Python respondió con error HTTP '.$response->status().'.'.$detail,
                );

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

    /**
     * @param  array<string, mixed>  $apaSettings
     */
    private function callPythonProcessor(string $absolute, string $filename, array $apaSettings): Response
    {
        $url = rtrim((string) config('services.python.url'), '/').'/process-document';
        $timeout = (int) config('services.python.timeout', 300);

        $headers = [
            'Accept' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];
        $apiKey = config('services.python.api_key');
        if (is_string($apiKey) && $apiKey !== '') {
            $headers['X-Internal-Api-Key'] = $apiKey;
        }

        return Http::timeout($timeout)
            ->withHeaders($headers)
            ->asMultipart()
            ->post($url, [
                [
                    'name' => 'file',
                    'contents' => file_get_contents($absolute),
                    'filename' => $filename,
                    'headers' => ['Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
                ],
                [
                    'name' => 'apa_settings',
                    'contents' => json_encode($apaSettings, JSON_UNESCAPED_UNICODE),
                ],
            ]);
    }

    private function assertPythonReachable(Document $document): bool
    {
        $base = rtrim((string) config('services.python.url'), '/');

        try {
            $response = Http::timeout(5)->get("{$base}/health");
            if (! $response->successful()) {
                $this->failDocument(
                    $document,
                    "Motor Python inalcanzable en {$base} (health HTTP {$response->status()}). Revise: sudo systemctl status apaflow-ai",
                );

                return false;
            }
        } catch (Throwable $e) {
            $hint = str_contains($e->getMessage(), 'Connection refused')
                ? ' Nada escucha en ese puerto: compruebe `curl 127.0.0.1:8001/health` y `curl 127.0.0.1:8000/health`; PYTHON_SERVICE_URL debe coincidir.'
                : '';
            $this->failDocument(
                $document,
                "No se pudo conectar al motor Python en {$base}: {$e->getMessage()}.{$hint}",
            );

            return false;
        }

        return true;
    }

    private function failDocument(Document $document, string $message): void
    {
        $document->addLog($message);
        $document->update(['status' => Document::STATUS_FAILED]);
    }
}
