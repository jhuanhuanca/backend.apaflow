<?php

namespace App\Console\Commands;

use App\Services\SaaS\SubscriptionExpirationService;
use Illuminate\Console\Command;

class ProcessProSubscriptionLifecycleCommand extends Command
{
    protected $signature = 'subscriptions:process-pro-lifecycle';

    protected $description = 'Expira suscripciones PRO vencidas, purga historial y limpia documentos FREE antiguos.';

    public function handle(SubscriptionExpirationService $expiration): int
    {
        $reminders = $expiration->sendExpiryReminders();
        $expired = $expiration->processExpiredBatch();
        $purged = $expiration->purgeExpiredMembershipHistoryBatch();
        $freeStale = $expiration->purgeStaleFreeDocumentsBatch();

        $this->info("Recordatorios enviados: {$reminders}");
        $this->info("Suscripciones expiradas: {$expired}");
        $this->info("Historiales PRO purgados: {$purged}");
        $this->info("Usuarios FREE con documentos antiguos purgados: {$freeStale}");

        return self::SUCCESS;
    }
}
