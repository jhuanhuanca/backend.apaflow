<?php

namespace App\Services\Documents;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Guarda .docx originales en el disco local (storage/app/private).
 */
class DocumentFileStorage
{
    public function storeOriginal(UploadedFile $file, string $ownerDirectory): string
    {
        $disk = Storage::disk('local');
        $relativeDir = 'documents/'.trim($ownerDirectory, '/');

        $storedPath = $file->store($relativeDir, 'local');

        if (! is_string($storedPath) || $storedPath === '' || ! $disk->exists($storedPath)) {
            Log::error('No se pudo persistir el documento original', [
                'owner_directory' => $ownerDirectory,
                'stored_path' => $storedPath,
                'disk_root' => config('filesystems.disks.local.root'),
                'original_name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
            ]);

            throw new RuntimeException(
                'No se pudo guardar el archivo en storage. '
                .'Comprueba permisos de escritura en storage/app/private (usuario www-data en producción).'
            );
        }

        return $storedPath;
    }

    public function originalExists(?string $path): bool
    {
        if (! is_string($path) || $path === '' || $path === '0') {
            return false;
        }

        return Storage::disk('local')->exists($path);
    }
}
