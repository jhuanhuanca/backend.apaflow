<?php

namespace App\Enums;

enum PaymentChannel: string
{
    case Card = 'card';
    case Qr = 'qr';

    public function label(): string
    {
        return match ($this) {
            self::Card => 'Tarjeta',
            self::Qr => 'QR / billetera',
        };
    }
}
