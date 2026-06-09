<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PaddleBillingService
{
    public static function isConfigured(): bool
    {
        if (! (bool) config('paddle.enabled', true)) {
            return false;
        }

        return filled(config('paddle.api_key'))
            && filled(config('paddle.client_token'))
            && filled(config('paddle.prices.pro_subscription'))
            && filled(config('paddle.prices.document_checkout'));
    }

    /**
     * @return array<string, mixed>
     */
    public function createTransaction(User $user, Payment $payment, string $priceId): array
    {
        $payload = [
            'items' => [
                [
                    'price_id' => $priceId,
                    'quantity' => 1,
                ],
            ],
            'custom_data' => [
                'payment_id' => (string) $payment->id,
                'user_id' => (string) $user->id,
                'flow' => (string) ($payment->metadata['flow'] ?? ''),
            ],
        ];

        $response = $this->request('POST', '/transactions', $payload);
        $transaction = $response['data'] ?? null;

        if (! is_array($transaction) || empty($transaction['id'])) {
            throw new RuntimeException('Paddle no devolvió una transacción válida.');
        }

        return $transaction;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fetchTransaction(string $transactionId): ?array
    {
        try {
            $response = $this->request('GET', '/transactions/'.$transactionId);

            return $response['data'] ?? null;
        } catch (RequestException $e) {
            Log::warning('paddle.fetch_transaction_failed', [
                'transaction_id' => $transactionId,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function verifyWebhookSignature(string $rawBody, ?string $signatureHeader): bool
    {
        $secret = (string) config('paddle.webhook_secret');

        if ($secret === '' || $signatureHeader === null || $signatureHeader === '') {
            return app()->environment('local') && (bool) config('app.debug');
        }

        $parts = [];
        foreach (explode(';', $signatureHeader) as $segment) {
            [$key, $value] = array_pad(explode('=', trim($segment), 2), 2, null);
            if ($key !== null && $value !== null) {
                $parts[$key] = $value;
            }
        }

        $timestamp = $parts['ts'] ?? null;
        $signature = $parts['h1'] ?? null;

        if (! $timestamp || ! $signature) {
            return false;
        }

        $signedPayload = $timestamp.':'.$rawBody;
        $expected = hash_hmac('sha256', $signedPayload, $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $payload = []): array
    {
        $apiKey = (string) config('paddle.api_key');
        $baseUrl = rtrim((string) config('paddle.api_base_url'), '/');

        if ($apiKey === '') {
            throw new RuntimeException('PADDLE_API_KEY no configurada.');
        }

        $client = Http::withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout(30);

        $response = match (strtoupper($method)) {
            'GET' => $client->get($baseUrl.$path),
            'POST' => $client->post($baseUrl.$path, $payload),
            'PATCH' => $client->patch($baseUrl.$path, $payload),
            default => throw new RuntimeException('Método HTTP no soportado.'),
        };

        $response->throw();

        return $response->json() ?? [];
    }
}
