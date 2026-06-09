<?php

namespace App\Enums;

enum PlanAccess: string
{
    case Free = 'free';
    case Pro = 'pro';
    case Both = 'both';

    public function label(): string
    {
        return match ($this) {
            self::Free => 'Free',
            self::Pro => 'Pro',
            self::Both => 'Free + Pro',
        };
    }
}
