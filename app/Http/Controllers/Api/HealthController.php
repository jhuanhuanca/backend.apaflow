<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $dbOk = false;
        $dbError = null;

        try {
            DB::connection()->getPdo();
            $dbOk = true;
        } catch (\Throwable $e) {
            $dbError = app()->environment('production') ? 'unavailable' : $e->getMessage();
        }

        $pythonUrl = rtrim((string) config('services.python.url'), '/');
        $python = [
            'url' => $pythonUrl,
            'ok' => false,
            'error' => null,
        ];

        try {
            $response = Http::timeout(3)->get("{$pythonUrl}/health");
            $python['ok'] = $response->successful();
            if (! $python['ok']) {
                $python['error'] = 'HTTP '.$response->status();
            }
        } catch (\Throwable $e) {
            $python['error'] = app()->environment('production')
                ? 'unreachable'
                : $e->getMessage();
        }

        $ok = $dbOk && $python['ok'];

        return response()->json([
            'ok' => $ok,
            'app' => config('app.name'),
            'environment' => app()->environment(),
            'database' => $dbOk ? 'connected' : 'error',
            'database_error' => $dbError,
            'python' => $python,
            'guest_schema_ready' => Schema::hasColumn('documents', 'guest_fingerprint'),
            'config_url' => url('/api/config'),
        ], $ok ? 200 : 503);
    }
}
