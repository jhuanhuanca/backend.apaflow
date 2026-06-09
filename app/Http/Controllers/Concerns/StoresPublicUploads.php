<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

trait StoresPublicUploads
{
    protected function storePublicFile(Request $request, string $field, string $directory): ?string
    {
        /** @var null|UploadedFile $file */
        $file = $request->file($field);

        if (! $file instanceof UploadedFile) {
            return null;
        }

        return $file->store($directory, 'public');
    }

    protected function deletePublicFile(?string $path): void
    {
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}
