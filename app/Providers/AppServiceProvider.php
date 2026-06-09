<?php

namespace App\Providers;

use App\Models\Document;
use App\Models\User;
use App\Policies\DocumentPolicy;
use App\Services\SaaS\SubscriptionService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::policy(Document::class, DocumentPolicy::class);

        Gate::define('customize-apa', function (?User $user): bool {
            return app(SubscriptionService::class)->canCustomizeApa($user);
        });

        Gate::define('unlimited-documents', function (?User $user): bool {
            return app(SubscriptionService::class)->hasUnlimitedDocuments($user);
        });

        Gate::define('process-without-payment', function (?User $user): bool {
            return app(SubscriptionService::class)->hasUnlimitedDocuments($user);
        });
    }
}
