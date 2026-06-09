<?php

namespace App\Modules\Analytics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Analytics\Services\MetricsQueryService;
use Illuminate\Http\JsonResponse;

class AdminAnalyticsController extends Controller
{
    public function kpi(MetricsQueryService $metrics): JsonResponse
    {
        return response()->json($metrics->kpiSnapshot());
    }
}
