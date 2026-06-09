<?php

namespace App\Services\SaaS;

use App\Enums\SubscriptionStatus;
use App\Enums\UserPlan;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Políticas de retención de historial:
 * - PRO vencido: purga total inmediata.
 * - FREE activo: documentos más antiguos que el periodo configurado (30 días por defecto).
 */
class UserHistoryPurgeService
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
    ) {}

    public function purgeForUser(User $user): bool
    {
        if (! config('saas.subscription.purge_history_on_expiry', true)) {
            return false;
        }

        if (! $this->shouldPurgeHistory($user) || $this->wasHistoryPurged($user)) {
            return false;
        }

        $deletedDocuments = 0;

        DB::transaction(function () use ($user, &$deletedDocuments) {
            $deletedDocuments = $this->deleteAllDocumentsForUser($user);
            $this->markHistoryPurged($user);
        });

        Log::info('subscription.history_purged', [
            'user_id' => $user->id,
            'email' => $user->email,
            'documents_deleted' => $deletedDocuments,
        ]);

        return true;
    }

    public function purgeStaleFreeDocumentsForUser(User $user): int
    {
        if (! $this->shouldApplyFreeRetention($user)) {
            return 0;
        }

        $cutoff = $this->freeRetentionCutoff();
        $documents = Document::query()
            ->where('user_id', $user->id)
            ->where('created_at', '<', $cutoff)
            ->get();

        if ($documents->isEmpty()) {
            return 0;
        }

        $deleted = 0;

        DB::transaction(function () use ($documents, $user, &$deleted) {
            $deleted = $this->deleteDocumentCollection($documents, $user);
        });

        if ($deleted > 0) {
            Log::info('subscription.free_history_purged', [
                'user_id' => $user->id,
                'email' => $user->email,
                'documents_deleted' => $deleted,
                'retention_days' => $this->freeRetentionDays(),
                'cutoff' => $cutoff->toIso8601String(),
            ]);
        }

        return $deleted;
    }

    public function purgeStaleFreeDocumentsBatch(): int
    {
        if (! $this->isFreeRetentionEnabled()) {
            return 0;
        }

        $cutoff = $this->freeRetentionCutoff();
        $usersProcessed = 0;

        User::query()
            ->where('plan', UserPlan::Free)
            ->where('is_blocked', false)
            ->whereHas('documents', fn ($query) => $query->where('created_at', '<', $cutoff))
            ->orderBy('id')
            ->chunkById(100, function ($users) use (&$usersProcessed) {
                foreach ($users as $user) {
                    if ($this->subscriptions->hasProAccess($user)) {
                        continue;
                    }

                    if ($this->purgeStaleFreeDocumentsForUser($user) > 0) {
                        $usersProcessed++;
                    }
                }
            });

        return $usersProcessed;
    }

    public function shouldPurgeHistory(User $user): bool
    {
        if ($user->plan !== UserPlan::Free) {
            return false;
        }

        if ($user->subscription_status !== SubscriptionStatus::Expired) {
            return false;
        }

        return $user->subscription_expires_at !== null
            || $user->subscription_started_at !== null;
    }

    public function shouldApplyFreeRetention(User $user): bool
    {
        if (! $this->isFreeRetentionEnabled()) {
            return false;
        }

        if ($user->is_blocked || $user->plan !== UserPlan::Free) {
            return false;
        }

        return ! $this->subscriptions->hasProAccess($user);
    }

    public function wasHistoryPurged(User $user): bool
    {
        $sent = $user->subscription_notifications_sent ?? [];

        return isset($sent['history_purged_at']);
    }

    public function freeRetentionDays(): int
    {
        return max(1, (int) config('saas.plans.free.document_retention_days', 30));
    }

    private function isFreeRetentionEnabled(): bool
    {
        return (bool) config('saas.plans.free.document_retention_enabled', true);
    }

    private function freeRetentionCutoff(): Carbon
    {
        return now()->subDays($this->freeRetentionDays());
    }

    private function deleteAllDocumentsForUser(User $user): int
    {
        $documents = Document::query()
            ->where('user_id', $user->id)
            ->get();

        return $this->deleteDocumentCollection($documents, $user);
    }

    private function deleteDocumentCollection(Collection $documents, User $user): int
    {
        $disk = Storage::disk('local');

        foreach ($documents as $document) {
            foreach (['original_file', 'processed_file'] as $field) {
                $path = $document->{$field};
                if (is_string($path) && $path !== '' && $disk->exists($path)) {
                    $disk->delete($path);
                }
            }

            $document->delete();
        }

        $remaining = Document::query()->where('user_id', $user->id)->count();
        if ($remaining === 0) {
            $directory = "documents/{$user->id}";
            if ($disk->exists($directory)) {
                $disk->deleteDirectory($directory);
            }
        }

        return $documents->count();
    }

    private function markHistoryPurged(User $user): void
    {
        $sent = $user->subscription_notifications_sent ?? [];
        $sent['history_purged_at'] = now()->toIso8601String();

        $user->forceFill([
            'subscription_notifications_sent' => $sent,
            'apa_settings' => null,
        ])->save();
    }
}
