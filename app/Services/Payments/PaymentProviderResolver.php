<?php

namespace App\Services\Payments;

class PaymentProviderResolver
{
    public static function current(): string
    {
        $preference = strtolower(trim((string) config('payments.provider', 'auto')));

        if ($preference === 'paddle' && PaddleBillingService::isServerConfigured()) {
            return 'paddle';
        }

        if ($preference === 'demo' && self::demoEnabled()) {
            return 'demo';
        }

        if ($preference === 'auto') {
            if (PaddleBillingService::isServerConfigured()) {
                return 'paddle';
            }
            if (self::demoEnabled()) {
                return 'demo';
            }
        }

        return 'none';
    }

    /**
     * @return list<string>
     */
    public static function missingConfiguration(): array
    {
        $preference = strtolower(trim((string) config('payments.provider', 'auto')));
        $missing = [];

        if (in_array($preference, ['paddle', 'auto'], true)) {
            $missing = array_merge($missing, PaddleBillingService::missingServerKeys());
        }

        if (! self::demoEnabled() && ! PaddleBillingService::isServerConfigured()) {
            $missing[] = 'PAYMENT_DEMO_UPGRADE (o credenciales Paddle completas)';
        }

        return array_values(array_unique($missing));
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
