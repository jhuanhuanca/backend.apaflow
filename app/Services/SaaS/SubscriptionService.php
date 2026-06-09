<?php

namespace App\Services\SaaS;

use App\Enums\DocumentBillingStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserPlan;
use App\Models\Document;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;

/**
 * Capa única de permisos de plan, facturación por documento y funciones premium.
 */
class SubscriptionService
{
    public function planSlug(?User $user): string
    {
        if (! $user) {
            return UserPlan::Free->value;
        }

        return $this->effectivePlan($user)->value;
    }

    public function effectivePlan(?User $user): UserPlan
    {
        if (! $user) {
            return UserPlan::Free;
        }

        if ($user->plan === UserPlan::Pro && ! $this->hasActiveProSubscription($user)) {
            return UserPlan::Free;
        }

        return $user->plan;
    }

    /**
     * Alias explícito — fuente única para acceso premium.
     */
    public function isProActive(?User $user): bool
    {
        return $this->hasProAccess($user);
    }

    public function hasProAccess(?User $user): bool
    {
        if (! $user || $user->is_blocked) {
            return false;
        }

        return match ($user->plan) {
            UserPlan::Pro => $this->hasActiveProSubscription($user),
            UserPlan::Trial => $user->trial_ends_at === null || $user->trial_ends_at->isFuture(),
            default => false,
        };
    }

    public function hasActiveProSubscription(User $user): bool
    {
        if (in_array($user->subscription_status, [
            SubscriptionStatus::Expired,
            SubscriptionStatus::Canceled,
            SubscriptionStatus::PastDue,
        ], true)) {
            return false;
        }

        if ($user->subscription_expires_at !== null && $user->subscription_expires_at->lte(now())) {
            return false;
        }

        return true;
    }

    public function isSubscriptionExpired(User $user): bool
    {
        if ($user->plan !== UserPlan::Pro) {
            return false;
        }

        if (in_array($user->subscription_status, [
            SubscriptionStatus::Expired,
            SubscriptionStatus::Canceled,
        ], true)) {
            return true;
        }

        return $user->subscription_expires_at !== null && $user->subscription_expires_at->lte(now());
    }

    public function hasUnlimitedDocuments(?User $user): bool
    {
        return $this->hasProAccess($user);
    }

    public function requiresPaymentPerDocument(?User $user): bool
    {
        if (! $user || $user->is_blocked) {
            return false;
        }

        return ! $this->hasProAccess($user);
    }

    public function canCustomizeApa(?User $user): bool
    {
        return $this->hasProAccess($user);
    }

    public function documentPriceCents(): int
    {
        return (int) config('saas.pricing.free_per_document_cents', 99);
    }

    public function proSubscriptionPriceCents(): int
    {
        return (int) config('saas.pricing.pro_monthly_cents', 599);
    }

    public function proSubscriptionDurationDays(): int
    {
        return (int) config('saas.subscription.pro_duration_days', 30);
    }

    public function defaultApaSettings(): array
    {
        return config('saas.apa_defaults', []);
    }

    public function resolvedApaSettings(?User $user): array
    {
        $defaults = $this->defaultApaSettings();

        if (! $this->canCustomizeApa($user) || ! $user?->apa_settings) {
            return $defaults;
        }

        return array_replace_recursive($defaults, $user->apa_settings);
    }

    public function billingStatusForNewDocument(?User $user): DocumentBillingStatus
    {
        if ($this->hasUnlimitedDocuments($user)) {
            return DocumentBillingStatus::IncludedInPro;
        }

        return DocumentBillingStatus::PendingPayment;
    }

    public function canProcessDocument(?User $user, Document $document): bool
    {
        if (! $user || $document->user_id !== $user->id) {
            return false;
        }

        if ($this->hasUnlimitedDocuments($user)) {
            return true;
        }

        return $document->billing_status?->allowsProcessing() ?? false;
    }

    public function canDownloadDocument(?User $user, Document $document): bool
    {
        if (! $user || $document->user_id !== $user->id) {
            return false;
        }

        if ($document->status !== Document::STATUS_COMPLETED || ! $document->processed_file) {
            return false;
        }

        if ($this->hasUnlimitedDocuments($user)) {
            return true;
        }

        return $document->billing_status?->allowsDownload() ?? false;
    }

    public function assertCanUpload(?User $user): void
    {
        if (! $user) {
            $this->deny('Debes iniciar sesión.', 'AUTH_REQUIRED', 401);
        }

        if ($user->is_blocked) {
            $this->deny('Tu cuenta está bloqueada.', 'ACCOUNT_BLOCKED', 403);
        }
    }

    public function assertCanDownload(?User $user, Document $document): void
    {
        if ($this->canDownloadDocument($user, $document)) {
            return;
        }

        if ($this->requiresPaymentPerDocument($user) && $document->billing_status === DocumentBillingStatus::PendingPayment) {
            $this->denyPaymentRequired($document);
        }

        $this->deny('No tienes permiso para descargar este documento.', 'DOWNLOAD_DENIED', 403);
    }

    public function assertCanCustomizeApa(?User $user): void
    {
        if ($this->canCustomizeApa($user)) {
            return;
        }

        $this->deny(
            'La personalización APA 7 avanzada requiere plan Pro.',
            'UPGRADE_REQUIRED',
            403,
        );
    }

    public function denyPaymentRequired(Document $document): never
    {
        throw new HttpResponseException(response()->json([
            'message' => 'Este documento requiere pago antes de continuar.',
            'code' => 'PAYMENT_REQUIRED',
            'document_id' => $document->id,
            'amount_cents' => $this->documentPriceCents(),
            'currency' => config('saas.pricing.currency', 'USD'),
            'formatted_price' => config('saas.pricing.free_per_document_formatted', '$0.99'),
        ], 402));
    }

    /**
     * @return array<string, mixed>
     */
    public function capabilitiesFor(?User $user): array
    {
        return [
            'plan' => $this->planSlug($user),
            'pro_active' => $this->isProActive($user),
            'unlimited_documents' => $this->hasUnlimitedDocuments($user),
            'requires_payment_per_document' => $this->requiresPaymentPerDocument($user),
            'can_customize_apa' => $this->canCustomizeApa($user),
            'document_price_cents' => $this->documentPriceCents(),
            'document_price_formatted' => config('saas.pricing.free_per_document_formatted', '$0.99'),
            'pro_monthly_cents' => $this->proSubscriptionPriceCents(),
            'pro_monthly_formatted' => config('saas.pricing.pro_monthly_formatted', '$5.99'),
            'show_ads' => app(AdsService::class)->canShowAds($user),
            'subscription_status' => $user?->subscription_status?->value,
            'subscription_started_at' => $user?->subscription_started_at?->toIso8601String(),
            'subscription_expires_at' => $user?->subscription_expires_at?->toIso8601String(),
            'subscription_notice' => app(SubscriptionExpirationService::class)->pendingNoticeFor($user),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function userPayload(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        $fresh = $user->fresh() ?? $user;

        return array_merge($fresh->toArray(), [
            'plan' => $this->planSlug($fresh),
            'capabilities' => $this->capabilitiesFor($fresh),
        ]);
    }

    /**
     * @internal Solo invocar desde PaymentConfirmationService tras pago confirmado (paid).
     */
    public function activateProSubscription(User $user, ?Carbon $expiresAt = null): void
    {
        $durationDays = $this->proSubscriptionDurationDays();
        $startedAt = now();
        $expires = $expiresAt;

        if ($expires === null) {
            if ($user->plan === UserPlan::Pro && $user->subscription_expires_at?->isFuture()) {
                $expires = $user->subscription_expires_at->copy()->addDays($durationDays);
                $startedAt = $user->subscription_started_at ?? $startedAt;
            } else {
                $expires = $startedAt->copy()->addDays($durationDays);
            }
        }

        $user->forceFill([
            'plan' => UserPlan::Pro,
            'subscription_status' => SubscriptionStatus::Active,
            'subscription_started_at' => $user->subscription_started_at ?? $startedAt,
            'subscription_expires_at' => $expires,
            'subscription_notifications_sent' => null,
        ])->save();
    }

    public function completeFreeRegistration(User $user): void
    {
        $user->forceFill([
            'plan' => UserPlan::Free,
            'subscription_status' => SubscriptionStatus::Active,
            'registration_checkout_completed_at' => now(),
        ])->save();
    }

    private function deny(string $message, string $code, int $status): never
    {
        throw new HttpResponseException(response()->json([
            'message' => $message,
            'code' => $code,
        ], $status));
    }
}
