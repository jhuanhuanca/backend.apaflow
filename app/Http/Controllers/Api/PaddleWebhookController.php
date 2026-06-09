<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Payments\PaddleBillingService;
use App\Services\Payments\PaddleWebhookHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaddleWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        PaddleBillingService $paddle,
        PaddleWebhookHandler $handler,
    ): JsonResponse {
        $raw = $request->getContent();
        $signature = $request->header('Paddle-Signature');

        if (! $paddle->verifyWebhookSignature($raw, $signature)) {
            Log::warning('paddle.webhook.invalid_signature');

            return response()->json(['message' => 'Firma inválida.'], 401);
        }

        /** @var array<string, mixed> $payload */
        $payload = json_decode($raw, true) ?? [];

        try {
            $handler->handle($payload);
        } catch (\Throwable $e) {
            Log::error('paddle.webhook.failed', [
                'message' => $e->getMessage(),
                'event_type' => $payload['event_type'] ?? null,
            ]);

            return response()->json(['message' => 'Error procesando webhook.'], 500);
        }

        return response()->json(['received' => true]);
    }
}
