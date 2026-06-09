<?php

namespace App\Services\SaaS;

use App\Enums\SubscriptionStatus;
use App\Enums\UserPlan;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Vencimiento automático de suscripciones PRO y recordatorios opcionales.
 */
class SubscriptionExpirationService
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly UserHistoryPurgeService $historyPurge,
    ) {}

    public function syncAuthenticatedUser(User $user): User
    {
        if ($this->expireIfDue($user)) {
            $this->markNotificationSent($user->fresh(), 'expired');
            $this->logEvent($user->fresh(), 'expired');
        } else {
            $this->historyPurge->purgeForUser($user->fresh());
        }

        $this->historyPurge->purgeStaleFreeDocumentsForUser($user->fresh());

        return $user->fresh();
    }

    public function expireIfDue(User $user): bool
    {
        if ($user->plan !== UserPlan::Pro) {
            return false;
        }

        if (! $this->subscriptions->isSubscriptionExpired($user)) {
            return false;
        }

        if ($user->subscription_status === SubscriptionStatus::Expired && $user->plan === UserPlan::Free) {
            return false;
        }

        return $this->downgradeToFree($user);
    }

    public function downgradeToFree(User $user): bool
    {
        if ($user->plan !== UserPlan::Pro) {
            return false;
        }

        $user->forceFill([
            'plan' => UserPlan::Free,
            'subscription_status' => SubscriptionStatus::Expired,
        ])->save();

        $this->historyPurge->purgeForUser($user->fresh());

        return true;
    }

    public function processExpiredBatch(): int
    {
        $count = 0;

        User::query()
            ->where('plan', UserPlan::Pro)
            ->whereNotNull('subscription_expires_at')
            ->where('subscription_expires_at', '<=', now())
            ->whereNotIn('subscription_status', [
                SubscriptionStatus::Expired->value,
                SubscriptionStatus::Canceled->value,
            ])
            ->orderBy('id')
            ->chunkById(100, function ($users) use (&$count) {
                foreach ($users as $user) {
                    if ($this->expireIfDue($user)) {
                        $fresh = $user->fresh();
                        $this->markNotificationSent($fresh, 'expired');
                        $this->logEvent($fresh, 'expired');
                        $count++;
                    }
                }
            });

        return $count;
    }

    public function purgeExpiredMembershipHistoryBatch(): int
    {
        $count = 0;

        User::query()
            ->where('plan', UserPlan::Free)
            ->where('subscription_status', SubscriptionStatus::Expired)
            ->where(function ($query) {
                $query->whereNotNull('subscription_expires_at')
                    ->orWhereNotNull('subscription_started_at');
            })
            ->orderBy('id')
            ->chunkById(100, function ($users) use (&$count) {
                foreach ($users as $user) {
                    if ($this->historyPurge->purgeForUser($user)) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    public function purgeStaleFreeDocumentsBatch(): int
    {
        return $this->historyPurge->purgeStaleFreeDocumentsBatch();
    }

    public function sendExpiryReminders(): int
    {
        $sent = 0;
        $sent += $this->sendReminderWindow(daysAhead: 7, key: '7_days', message: 'Tu suscripción PRO vence en 7 días.');
        $sent += $this->sendReminderWindow(daysAhead: 3, key: '3_days', message: 'Tu suscripción PRO vence en 3 días.');

        return $sent;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function pendingNoticeFor(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        $sent = $user->subscription_notifications_sent ?? [];

        if ($user->subscription_status === SubscriptionStatus::Expired && isset($sent['expired'])) {
            return [
                'code' => 'pro_expired',
                'message' => 'Tu suscripción PRO ha expirado. Tu cuenta regresó al plan FREE y tu historial de documentos fue eliminado.',
            ];
        }

        if ($user->plan !== UserPlan::Pro || ! $user->subscription_expires_at) {
            return null;
        }

        if ($this->subscriptions->isSubscriptionExpired($user)) {
            return [
                'code' => 'pro_expired',
                'message' => 'Tu suscripción PRO ha expirado. Tu cuenta regresó al plan FREE y tu historial de documentos fue eliminado.',
            ];
        }

        $daysLeft = (int) now()->startOfDay()->diffInDays(
            $user->subscription_expires_at->copy()->startOfDay(),
            false,
        );

        if ($daysLeft <= 3 && $daysLeft >= 0) {
            return [
                'code' => 'pro_expiring_3d',
                'message' => 'Tu suscripción PRO vence en 3 días.',
                'expires_at' => $user->subscription_expires_at->toIso8601String(),
            ];
        }

        if ($daysLeft <= 7 && $daysLeft > 3) {
            return [
                'code' => 'pro_expiring_7d',
                'message' => 'Tu suscripción PRO vence en 7 días.',
                'expires_at' => $user->subscription_expires_at->toIso8601String(),
            ];
        }

        return null;
    }

    private function sendReminderWindow(int $daysAhead, string $key, string $message): int
    {
        $sent = 0;
        $targetStart = now()->addDays($daysAhead - 1)->startOfDay();
        $targetEnd = now()->addDays($daysAhead)->endOfDay();

        User::query()
            ->where('plan', UserPlan::Pro)
            ->where('subscription_status', SubscriptionStatus::Active)
            ->whereNotNull('subscription_expires_at')
            ->whereBetween('subscription_expires_at', [$targetStart, $targetEnd])
            ->orderBy('id')
            ->chunkById(100, function ($users) use ($key, $message, &$sent) {
                foreach ($users as $user) {
                    if ($this->wasNotificationSent($user, $key)) {
                        continue;
                    }

                    $this->markNotificationSent($user, $key);
                    $this->logEvent($user->fresh(), 'reminder_'.$key, ['message' => $message]);
                    $sent++;
                }
            });

        return $sent;
    }

    private function wasNotificationSent(User $user, string $key): bool
    {
        $sent = $user->subscription_notifications_sent ?? [];

        return isset($sent[$key]);
    }

    private function markNotificationSent(User $user, string $key): void
    {
        $sent = $user->subscription_notifications_sent ?? [];
        $sent[$key] = now()->toIso8601String();

        $user->forceFill([
            'subscription_notifications_sent' => $sent,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logEvent(User $user, string $event, array $context = []): void
    {
        Log::info('subscription.'.$event, array_merge([
            'user_id' => $user->id,
            'email' => $user->email,
            'plan' => $user->plan?->value,
            'subscription_status' => $user->subscription_status?->value,
            'subscription_expires_at' => $user->subscription_expires_at?->toIso8601String(),
        ], $context));
    }
}
