<?php

namespace App\Services\Documents;

use App\Enums\DocumentBillingStatus;
use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use RuntimeException;

class DocumentProcessingService
{
    public function __construct(
        private readonly DocumentFileStorage $documentStorage,
    ) {}

    /**
     * Encola un documento recién subido (estado pending, facturación que permite procesar).
     */
    public function queueNewDocument(Document $document): void
    {
        $document = $document->fresh();

        if ($document->status !== Document::STATUS_PENDING) {
            return;
        }

        if (! $document->billing_status->allowsProcessing()) {
            return;
        }

        if (! $this->documentStorage->originalExists($document->original_file)) {
            $document->update(['status' => Document::STATUS_FAILED]);
            $document->addLog('No se puede procesar: archivo original no encontrado en storage.');

            return;
        }

        ProcessDocumentJob::dispatch($document);
    }

    /**
     * Reencola el formateo APA si el documento está pagado y falló previamente.
     */
    public function ensureQueued(Document $document): bool
    {
        $document = $document->fresh();

        if ($document->status === Document::STATUS_COMPLETED) {
            return false;
        }

        if ($document->status === Document::STATUS_PROCESSING) {
            return false;
        }

        if (! $document->billing_status->allowsProcessing()) {
            return false;
        }

        return $this->dispatchIfOriginalExists($document);
    }

    /**
     * Reintento manual tras un fallo (usuario autenticado, documento pagado).
     */
    public function retry(Document $document): Document
    {
        $document = $document->fresh(['logs']);

        if (! $document->billing_status->allowsProcessing()) {
            throw new RuntimeException('El documento aún no está pagado.');
        }

        if ($document->status === Document::STATUS_COMPLETED) {
            return $document;
        }

        if ($document->status === Document::STATUS_PROCESSING) {
            throw new RuntimeException('El documento ya se está procesando.');
        }

        if (! $this->documentStorage->originalExists($document->original_file)) {
            $hint = $document->original_file === '0' || $document->original_file === ''
                ? ' Vuelve a subir el archivo .docx.'
                : '';

            throw new RuntimeException(
                'No se encontró el archivo original en el servidor.'.$hint
            );
        }

        $document->update([
            'status' => Document::STATUS_PENDING,
            'processed_file' => null,
        ]);
        $document->addLog('Reintento de formateo APA 7 solicitado.');
        ProcessDocumentJob::dispatch($document->fresh());

        return $document->fresh(['logs']);
    }

    private function dispatchIfOriginalExists(Document $document): bool
    {
        if (! $this->documentStorage->originalExists($document->original_file)) {
            if ($document->status !== Document::STATUS_FAILED) {
                $document->update(['status' => Document::STATUS_FAILED]);
                $document->addLog(
                    'No se puede procesar: archivo original no encontrado en storage.'
                    .($document->original_file === '0' || $document->original_file === ''
                        ? ' La ruta en BD es inválida; vuelve a subir el .docx.'
                        : '')
                );
            }

            return false;
        }

        if ($document->status === Document::STATUS_FAILED) {
            $document->update([
                'status' => Document::STATUS_PENDING,
                'processed_file' => null,
            ]);
            $document->addLog('Formateo APA 7 reencolado tras confirmación de pago.');
            ProcessDocumentJob::dispatch($document->fresh());

            return true;
        }

        return false;
    }
}
