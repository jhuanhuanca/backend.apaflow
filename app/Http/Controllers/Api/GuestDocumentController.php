<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use App\Services\Documents\DocumentFileStorage;
use App\Services\SaaS\CareerSelectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Modo invitado: límites desde config/saas.php (expuestos en GET /api/config).
 */
class GuestDocumentController extends Controller
{
    private function maxGuestUploads(): int
    {
        return max(1, (int) config('saas.guest_max_uploads', 1));
    }

    public function __construct(
        private readonly CareerSelectionService $careerSelection,
        private readonly DocumentFileStorage $documentStorage,
    ) {}

    /**
     * Si no se ha ejecutado la migración, respuesta clara (evita 500 genérico).
     */
    private function guestSchemaReady(): ?JsonResponse
    {
        if (! Schema::hasColumn('documents', 'guest_fingerprint')) {
            return response()->json([
                'message' => 'La base de datos no está actualizada. Ejecuta en backend-laravel: php artisan migrate (usa PHP 8.2+ de Laragon).',
                'code' => 'SCHEMA_GUEST_MISSING',
            ], 503);
        }

        return null;
    }

    private function fingerprint(Request $request): string
    {
        return hash('sha256', $request->ip().'|'.$request->userAgent());
    }

    private function guestDir(string $fingerprint): string
    {
        return 'guest/'.substr($fingerprint, 0, 16);
    }

    public function trialsRemaining(Request $request): JsonResponse
    {
        if ($err = $this->guestSchemaReady()) {
            return $err;
        }

        $fp = $this->fingerprint($request);
        $used = Document::query()->where('guest_fingerprint', $fp)->count();
        $max = $this->maxGuestUploads();

        return response()->json([
            'remaining' => max(0, $max - $used),
            'used' => min($max, $used),
            'max' => $max,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        if ($err = $this->guestSchemaReady()) {
            return $err;
        }

        $fp = $this->fingerprint($request);
        $documents = Document::query()
            ->whereNull('user_id')
            ->where('guest_fingerprint', $fp)
            ->orderByDesc('id')
            ->get(['id', 'original_file', 'processed_file', 'status', 'university', 'career', 'career_id', 'created_at', 'updated_at']);

        return response()->json($documents);
    }

    public function upload(Request $request): JsonResponse
    {
        if ($err = $this->guestSchemaReady()) {
            return $err;
        }

        try {
            $fp = $this->fingerprint($request);
            $used = Document::query()->where('guest_fingerprint', $fp)->count();
            $max = $this->maxGuestUploads();
            if ($used >= $max) {
                return response()->json([
                    'message' => "Has usado tu prueba gratuita sin cuenta ({$max}). Regístrate para seguir procesando documentos.",
                ], 403);
            }

            $validated = $request->validate([
                'file' => ['required', 'file', 'mimes:docx', 'max:51200'],
                'career_id' => ['nullable', 'integer', 'exists:careers,id'],
                'university' => ['required_without:career_id', 'string', 'max:255'],
                'career' => ['required_without:career_id', 'string', 'max:255'],
            ]);

            $careerId = isset($validated['career_id']) ? (int) $validated['career_id'] : null;
            $universityLabel = $validated['university'] ?? '';
            $careerLabel = $validated['career'] ?? '';

            if ($careerId) {
                $resolved = $this->careerSelection->resolveCareer(null, $careerId);
                $universityLabel = $resolved['university']->name;
                $careerLabel = $resolved['career']->name;
            }

            $dir = $this->guestDir($fp);

            try {
                $storedPath = $this->documentStorage->storeOriginal(
                    $request->file('file'),
                    $dir,
                );
            } catch (RuntimeException $e) {
                Log::error('Fallo upload documento (invitado)', [
                    'guest_dir' => $dir,
                    'error' => $e->getMessage(),
                ]);

                return response()->json([
                    'message' => $e->getMessage(),
                    'code' => 'STORAGE_WRITE_FAILED',
                ], 500);
            }

            $document = Document::create([
                'user_id' => null,
                'career_id' => $careerId,
                'guest_fingerprint' => $fp,
                'original_file' => $storedPath,
                'processed_file' => null,
                'status' => Document::STATUS_PENDING,
                'university' => $universityLabel,
                'career' => $careerLabel,
            ]);

            $document->addLog('Prueba gratuita (sin registro). Archivo guardado.');
            $document->addLog('Documento encolado para formateo APA 7.');

            try {
                ProcessDocumentJob::dispatch($document);
            } catch (Throwable $queueError) {
                Log::error('No se pudo encolar ProcessDocumentJob (invitado)', [
                    'document_id' => $document->id,
                    'error' => $queueError->getMessage(),
                ]);
                $document->update(['status' => Document::STATUS_FAILED]);
                $document->addLog('Error al encolar el procesamiento. Revisa Redis/cola en el servidor.');

                return response()->json([
                    'message' => 'El archivo se guardó pero la cola de procesamiento no está disponible. Intenta más tarde o contacta soporte.',
                    'code' => 'QUEUE_UNAVAILABLE',
                    'document_id' => $document->id,
                ], 503);
            }

            return response()->json($document->load('logs'), 202);
        } catch (Throwable $e) {
            Log::error('Fallo upload invitado', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'No se pudo subir el documento. Verifica permisos de storage y migraciones en el servidor.',
                'code' => 'GUEST_UPLOAD_FAILED',
            ], 500);
        }
    }

    public function download(Request $request, int $id): StreamedResponse|JsonResponse
    {
        if ($err = $this->guestSchemaReady()) {
            return $err;
        }

        $fp = $this->fingerprint($request);
        $document = Document::query()
            ->whereNull('user_id')
            ->where('guest_fingerprint', $fp)
            ->findOrFail($id);

        if ($document->status !== Document::STATUS_COMPLETED || ! $document->processed_file) {
            return response()->json(['message' => 'El documento aún no está listo para descarga.'], 409);
        }

        if (! Storage::disk('local')->exists($document->processed_file)) {
            return response()->json(['message' => 'Archivo procesado no encontrado.'], 404);
        }

        $name = basename($document->processed_file);

        return Storage::disk('local')->download($document->processed_file, "apa7_{$name}");
    }
}
