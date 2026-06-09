<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Canceled = 'canceled';
    case Refunded = 'refunded';

    public function isConfirmed(): bool
    {
        return $this === self::Paid;
    }
}
