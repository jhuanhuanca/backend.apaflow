<?php

namespace App\Modules\AIIntegration\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Metadatos de integración con el microservicio Python (URLs desde env; sin secretos).
 */
class AdminAiIntegrationController extends Controller
{
    public function capabilities(): JsonResponse
    {
        $base = rtrim((string) config('services.python.url'), '/');

        return response()->json([
            'python_service_base_url' => $base,
            'endpoints' => [
                'health' => "{$base}/health",
                'process_document' => "{$base}/process-document",
                'intelligence_format' => "{$base}/v1/intelligence/format",
                'intelligence_structure' => "{$base}/v1/intelligence/structure",
                'intelligence_reorder' => "{$base}/v1/intelligence/reorder",
                'intelligence_citations' => "{$base}/v1/intelligence/citations",
                'intelligence_index' => "{$base}/v1/intelligence/index",
            ],
            'notes' => 'Laravel debe llamar a Python con API key interna (configurar en producción).',
        ]);
    }
}
