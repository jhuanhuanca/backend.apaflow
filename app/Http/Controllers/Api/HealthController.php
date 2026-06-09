<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
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

        return response()->json([
            'ok' => $dbOk,
            'app' => config('app.name'),
            'environment' => app()->environment(),
            'database' => $dbOk ? 'connected' : 'error',
            'database_error' => $dbError,
            'guest_schema_ready' => Schema::hasColumn('documents', 'guest_fingerprint'),
            'config_url' => url('/api/config'),
        ], $dbOk ? 200 : 503);
    }
}
