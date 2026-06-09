<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use App\Services\SaaS\CareerSelectionService;
use App\Services\SaaS\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function __construct(
        private readonly CareerSelectionService $careerSelection,
        private readonly SubscriptionService $subscriptions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $documents = Document::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->get([
                'id',
                'original_file',
                'processed_file',
                'status',
                'billing_status',
                'payment_id',
                'university',
                'career',
                'career_id',
                'created_at',
                'updated_at',
            ]);

        return response()->json($documents);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $document = Document::query()
            ->where('user_id', $request->user()->id)
            ->with('logs')
            ->findOrFail($id);

        $this->authorize('view', $document);

        return response()->json($document);
    }

    public function upload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:docx', 'max:51200'],
            'career_id' => ['nullable', 'integer', 'exists:careers,id'],
            'university' => ['required_without:career_id', 'string', 'max:255'],
            'career' => ['required_without:career_id', 'string', 'max:255'],
        ]);

        $user = $request->user();
        $this->subscriptions->assertCanUpload($user);

        $careerId = isset($validated['career_id']) ? (int) $validated['career_id'] : null;
        $universityLabel = $validated['university'] ?? '';
        $careerLabel = $validated['career'] ?? '';

        if ($careerId) {
            $resolved = $this->careerSelection->resolveCareer($user, $careerId);
            $universityLabel = $resolved['university']->name;
            $careerLabel = $resolved['career']->name;
        }

        $billingStatus = $this->subscriptions->billingStatusForNewDocument($user);

        $storedPath = $request->file('file')->store("documents/{$user->id}", 'local');

        $document = Document::create([
            'user_id' => $user->id,
            'career_id' => $careerId,
            'original_file' => $storedPath,
            'processed_file' => null,
            'status' => Document::STATUS_PENDING,
            'billing_status' => $billingStatus,
            'university' => $universityLabel,
            'career' => $careerLabel,
        ]);

        $document->addLog('Archivo recibido y guardado en almacenamiento local.');

        if ($billingStatus->allowsProcessing()) {
            $document->addLog('Documento encolado para formateo APA 7.');
            ProcessDocumentJob::dispatch($document);

            return response()->json($document->load('logs'), 202);
        }

        $document->addLog('Pendiente de pago para iniciar el formateo APA 7.');

        return response()->json([
            'message' => 'Documento recibido. Completa el pago para iniciar la conversión.',
            'code' => 'PAYMENT_REQUIRED',
            'document' => $document->load('logs'),
            'amount_cents' => $this->subscriptions->documentPriceCents(),
            'currency' => config('saas.pricing.currency', 'USD'),
            'formatted_price' => config('saas.pricing.free_per_document_formatted', '$0.99'),
        ], 402);
    }

    public function download(Request $request, int $id): StreamedResponse|JsonResponse
    {
        $user = $request->user();

        $document = Document::query()
            ->where('user_id', $user->id)
            ->findOrFail($id);

        $this->subscriptions->assertCanDownload($user, $document);

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
