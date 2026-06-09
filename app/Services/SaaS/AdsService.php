<?php

namespace App\Services\SaaS;

use App\Models\AdPlacement;
use App\Models\User;
use Illuminate\Support\Collection;

class AdsService
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
    ) {}

    /**
     * Única fuente de verdad: guest/free => true, pro/trial unlimited => false.
     */
    public function canShowAds(?User $user): bool
    {
        if (! (bool) config('ads.enabled', false)) {
            return false;
        }

        if ($user === null) {
            return true;
        }

        return ! $this->subscriptions->hasProAccess($user);
    }

    /**
     * @return array<string, mixed>
     */
    public function publicConfig(): array
    {
        return [
            'enabled' => (bool) config('ads.enabled', false),
            'provider' => (string) config('ads.provider', 'placeholder'),
            'lazy_load' => (bool) config('ads.lazy_load', true),
            'slots' => config('ads.slots', []),
            'inline_interval' => (int) config('ads.inline_interval', 3),
            'blocked_paths' => config('ads.blocked_paths', []),
        ];
    }

    /**
     * @return Collection<int, AdPlacement>
     */
    public function activePlacements(string $location, ?User $user = null): Collection
    {
        if (! $this->canShowAds($user)) {
            return collect();
        }

        $isGuest = $user === null;

        return AdPlacement::query()
            ->where('location', $location)
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->where(function ($q) use ($isGuest) {
                $q->whereIn('audience', ['non_pro', 'all']);
                if ($isGuest) {
                    $q->orWhereIn('audience', ['guest', 'guest_and_free']);
                } else {
                    $q->orWhereIn('audience', ['free', 'guest_and_free']);
                }
            })
            ->orderBy('priority')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function serializePlacements(Collection $placements): array
    {
        return $placements->map(fn (AdPlacement $ad) => [
            'id' => $ad->id,
            'name' => $ad->name,
            'location' => $ad->location,
            'format' => $ad->format,
            'provider' => $ad->provider,
            'slot_id' => $ad->slot_id,
            'label' => $ad->label ?? 'Publicidad',
            'priority' => $ad->priority,
        ])->values()->all();
    }
}
