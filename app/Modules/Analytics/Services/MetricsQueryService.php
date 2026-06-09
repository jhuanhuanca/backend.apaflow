<?php

namespace App\Modules\Analytics\Services;

use App\Enums\UserPlan;
use App\Models\Document;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Consultas agregadas para KPI del panel admin (base para gráficos y reporting).
 */
class MetricsQueryService
{
    public function kpiSnapshot(): array
    {
        $totalUsers = User::query()->count();
        $activeUsers = User::query()
            ->where('is_blocked', false)
            ->whereIn('subscription_status', ['active', 'trial'])
            ->count();

        $byPlan = User::query()
            ->selectRaw('plan, COUNT(*) as c')
            ->groupBy('plan')
            ->pluck('c', 'plan')
            ->all();

        $proCount = (int) User::query()
            ->where('plan', UserPlan::Pro->value)
            ->where('subscription_status', 'active')
            ->where(function ($q) {
                $q->whereNull('subscription_expires_at')
                    ->orWhere('subscription_expires_at', '>', now());
            })
            ->count();
        $freeCount = (int) (User::query()->where('plan', UserPlan::Free->value)->count());
        $conversion = $freeCount + $proCount > 0
            ? round($proCount / ($freeCount + $proCount) * 100, 2)
            : 0.0;

        $revenueCents = (int) Payment::query()->where('status', 'paid')->sum('amount_cents');

        $documentsByCareer = Document::query()
            ->select('career', DB::raw('COUNT(*) as c'))
            ->whereNotNull('career')
            ->groupBy('career')
            ->orderByDesc('c')
            ->limit(10)
            ->get()
            ->map(fn ($r) => ['career' => $r->career, 'count' => (int) $r->c])
            ->values()
            ->all();

        $dailyActive = Document::query()
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('DATE(created_at) as d, COUNT(*) as c')
            ->groupBy('d')
            ->orderBy('d')
            ->get()
            ->map(fn ($r) => ['date' => $r->d, 'documents' => (int) $r->c])
            ->values()
            ->all();

        return [
            'users_total' => $totalUsers,
            'users_active' => $activeUsers,
            'users_by_plan' => $byPlan,
            'conversion_free_to_pro_pct' => $conversion,
            'revenue_paid_cents' => $revenueCents,
            'top_careers_by_documents' => $documentsByCareer,
            'activity_documents_daily_30d' => $dailyActive,
        ];
    }
}
