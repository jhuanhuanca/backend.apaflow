<?php

namespace App\Services\Payments;

class PaymentProviderResolver
{
    public static function current(): string
    {
        $preference = strtolower(trim((string) config('payments.provider', 'auto')));

        if ($preference === 'paddle' && PaddleBillingService::isConfigured()) {
            return 'paddle';
        }

        if ($preference === 'demo' && self::demoEnabled()) {
            return 'demo';
        }

        if ($preference === 'auto') {
            if (PaddleBillingService::isConfigured()) {
                return 'paddle';
            }
            if (self::demoEnabled()) {
                return 'demo';
            }
        }

        return 'none';
    }

    public static function demoEnabled(): bool
    {
        return (bool) config('payments.demo_upgrade_enabled', false);
    }

    public static function isPaddle(): bool
    {
        return self::current() === 'paddle';
    }

    public static function isDemo(): bool
    {
        return self::current() === 'demo';
    }
}
