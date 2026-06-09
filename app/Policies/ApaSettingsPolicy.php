<?php

namespace App\Policies;

use App\Models\User;
use App\Services\SaaS\SubscriptionService;

class ApaSettingsPolicy
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
    ) {}

    public function view(?User $user): bool
    {
        return $user !== null;
    }

    public function update(User $user): bool
    {
        return $this->subscriptions->canCustomizeApa($user);
    }
}
