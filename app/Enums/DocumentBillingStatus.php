<?php

namespace App\Enums;

enum DocumentBillingStatus: string
{
    case PendingPayment = 'pending_payment';
    case Paid = 'paid';
    case IncludedInPro = 'included_in_pro';

    public function allowsProcessing(): bool
    {
        return match ($this) {
            self::Paid, self::IncludedInPro => true,
            default => false,
        };
    }

    public function allowsDownload(): bool
    {
        return $this->allowsProcessing();
    }
}
