<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Opciones de pasarelas y canales (tarjeta / QR) para checkout y documentación de integración.
 */
class PaymentOptionsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $gateways = collect(config('payments.gateways', []))
            ->map(function (array $meta, string $code) {
                $envKey = $meta['env'] ?? null;
                $configured = $envKey === null
                    ? true
                    : filled(env($envKey));

                return [
                    'code' => $code,
                    'label' => $meta['label'] ?? $code,
                    'description' => $meta['description'] ?? null,
                    'channels' => $meta['channels'] ?? ['card'],
                    'configured' => $configured,
                ];
            })
            ->values();

        $labels = config('payments.channel_labels', []);

        return response()->json([
            'data' => $gateways,
            'channels' => [
                ['code' => 'card', 'label' => $labels['card'] ?? 'Tarjeta'],
                ['code' => 'qr', 'label' => $labels['qr'] ?? 'QR'],
            ],
        ]);
    }
}
