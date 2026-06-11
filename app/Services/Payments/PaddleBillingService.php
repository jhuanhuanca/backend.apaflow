<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class PaddleBillingService
{
    /**
     * Backend: crear transacciones vía API (no requiere client_token).
     */
    public static function isServerConfigured(): bool
    {
        if (! (bool) config('paddle.enabled', true)) {
            return false;
        }

        return filled(config('paddle.api_key'))
            && filled(config('paddle.prices.pro_subscription'))
            && filled(config('paddle.prices.document_checkout'));
    }

    /**
     * Frontend: overlay Paddle.js (requiere client_token además del API key).
     */
    public static function isClientConfigured(): bool
    {
        return self::isServerConfigured() && filled(config('paddle.client_token'));
    }

    /** @deprecated Usar isServerConfigured() o isClientConfigured() según el contexto. */
    public static function isConfigured(): bool
    {
        return self::isClientConfigured();
    }

    /**
     * @return list<string>
     */
    public static function missingServerKeys(): array
    {
        $missing = [];

        if (! (bool) config('paddle.enabled', true)) {
            $missing[] = 'PADDLE_ENABLED';
        }
        if (! filled(config('paddle.api_key'))) {
            $missing[] = 'PADDLE_API_KEY';
        }
        if (! filled(config('paddle.prices.pro_subscription'))) {
            $missing[] = 'PADDLE_PRICE_PRO';
        }
        if (! filled(config('paddle.prices.document_checkout'))) {
            $missing[] = 'PADDLE_PRICE_DOCUMENT';
        }

        return $missing;
    }

    /**
     * @return array<string, mixed>
     */
    public function createTransaction(User $user, Payment $payment, string $priceId): array
    {
        $resolvedPriceId = $this->resolvePriceId($priceId);
        $customerId = $this->resolveCustomerId($user);

        $payload = [
            'items' => [
                [
                    'price_id' => $resolvedPriceId,
                    'quantity' => 1,
                ],
            ],
            'customer_id' => $customerId,
            'collection_mode' => 'automatic',
            'custom_data' => [
                'payment_id' => (string) $payment->id,
                'user_id' => (string) $user->id,
                'flow' => (string) ($payment->metadata['flow'] ?? ''),
            ],
        ];

        $successUrl = (string) config('paddle.checkout.success_url');
        $cancelUrl = (string) config('paddle.checkout.cancel_url');
        if ($successUrl !== '' || $cancelUrl !== '') {
            $payload['checkout'] = array_filter([
                'success_url' => $successUrl !== '' ? $successUrl : null,
                'cancel_url' => $cancelUrl !== '' ? $cancelUrl : null,
            ]);
        }

        $response = $this->request('POST', '/transactions', $payload);
        $transaction = $response['data'] ?? null;

        if (! is_array($transaction) || empty($transaction['id'])) {
            throw new RuntimeException('Paddle no devolvió una transacción válida.');
        }

        return $transaction;
    }

    /**
     * @return array{provider: string, transaction_id: ?string, checkout_url: ?string}
     */
    public function checkoutPayloadFromTransaction(array $transaction): array
    {
        $checkout = is_array($transaction['checkout'] ?? null) ? $transaction['checkout'] : [];

        return [
            'provider' => 'paddle',
            'transaction_id' => $transaction['id'] ?? null,
            'checkout_url' => $checkout['url'] ?? $transaction['checkout_url'] ?? null,
        ];
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

    public function publicErrorMessage(Throwable $e): string
    {
        if ($e instanceof RequestException) {
            $parsed = $this->parseRequestException($e);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        $message = trim($e->getMessage());
        if ($message === '') {
            return 'No se pudo iniciar checkout Paddle. Revisa credenciales y Price IDs en el servidor.';
        }

        if (str_contains($message, 'pro_*')) {
            return $message;
        }

        return 'No se pudo iniciar checkout Paddle: '.$message;
    }

    /**
     * Diagnóstico rápido (CLI / health).
     *
     * @return array<string, mixed>
     */
    public function diagnostics(): array
    {
        $apiKey = (string) config('paddle.api_key');
        $clientToken = (string) config('paddle.client_token');

        return [
            'server_configured' => self::isServerConfigured(),
            'client_configured' => self::isClientConfigured(),
            'api_base_url' => $this->apiBaseUrl(),
            'sandbox_mode' => $this->isSandboxKey($apiKey) || (bool) config('paddle.sandbox', true),
            'api_key_prefix' => $this->keyPrefix($apiKey),
            'client_token_prefix' => $this->keyPrefix($clientToken),
            'price_pro' => (string) config('paddle.prices.pro_subscription'),
            'price_document' => (string) config('paddle.prices.document_checkout'),
            'missing' => self::missingServerKeys(),
        ];
    }

    private function resolveCustomerId(User $user): string
    {
        $email = trim((string) $user->email);
        if ($email === '') {
            throw new RuntimeException('El usuario no tiene email; Paddle requiere un cliente con email.');
        }

        $list = $this->request('GET', '/customers?'.http_build_query([
            'email' => $email,
            'per_page' => 1,
            'status' => 'active',
        ]));

        $existing = $list['data'][0] ?? null;
        if (is_array($existing) && ! empty($existing['id'])) {
            return (string) $existing['id'];
        }

        $created = $this->request('POST', '/customers', [
            'email' => $email,
            'name' => trim((string) $user->name) !== '' ? $user->name : $email,
        ]);

        $customer = $created['data'] ?? null;
        if (! is_array($customer) || empty($customer['id'])) {
            throw new RuntimeException('Paddle no devolvió un cliente válido al crearlo.');
        }

        return (string) $customer['id'];
    }

    /**
     * Acepta pri_* (price) o pro_* (product → resuelve el primer precio activo).
     */
    private function resolvePriceId(string $configuredId): string
    {
        $id = trim($configuredId);
        if ($id === '') {
            throw new RuntimeException('PADDLE_PRICE_* vacío en configuración.');
        }

        if (str_starts_with($id, 'pri_')) {
            return $id;
        }

        if (str_starts_with($id, 'pro_')) {
            Log::warning('paddle.price_id_is_product', ['product_id' => $id]);

            $response = $this->request('GET', '/prices?'.http_build_query([
                'product_id' => $id,
                'status' => 'active',
                'per_page' => 50,
            ]));

            $prices = $response['data'] ?? [];
            if (! is_array($prices) || $prices === []) {
                throw new RuntimeException(
                    'PADDLE_PRICE_* apunta a un producto (pro_*), no a un precio (pri_*). '
                    .'No hay precios activos para ese producto en Paddle. Crea un precio en el catálogo y usa su ID pri_*.'
                );
            }

            foreach ($prices as $price) {
                if (is_array($price) && ! empty($price['id']) && str_starts_with((string) $price['id'], 'pri_')) {
                    return (string) $price['id'];
                }
            }

            throw new RuntimeException(
                'No se pudo resolver un precio pri_* para el producto '.$id.'. Usa directamente el Price ID del catálogo Paddle.'
            );
        }

        throw new RuntimeException(
            'PADDLE_PRICE_* inválido ('.$id.'). Debe empezar por pri_ (precio) o pro_ (producto con precios activos).'
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $payload = []): array
    {
        $apiKey = (string) config('paddle.api_key');
        $baseUrl = $this->apiBaseUrl();

        if ($apiKey === '') {
            throw new RuntimeException('PADDLE_API_KEY no configurada.');
        }

        $client = Http::withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout(30);

        $url = $baseUrl.$path;

        try {
            $response = match (strtoupper($method)) {
                'GET' => $client->get($url),
                'POST' => $client->post($url, $payload),
                'PATCH' => $client->patch($url, $payload),
                default => throw new RuntimeException('Método HTTP no soportado.'),
            };

            $response->throw();
        } catch (RequestException $e) {
            Log::error('paddle.api_request_failed', [
                'method' => $method,
                'path' => $path,
                'base_url' => $baseUrl,
                'status' => $e->response?->status(),
                'body' => mb_substr((string) $e->response?->body(), 0, 1200),
            ]);

            throw $e;
        }

        return $response->json() ?? [];
    }

    private function apiBaseUrl(): string
    {
        $apiKey = (string) config('paddle.api_key');

        if (str_starts_with($apiKey, 'test_')) {
            return 'https://sandbox-api.paddle.com';
        }

        if (str_starts_with($apiKey, 'live_')) {
            return 'https://api.paddle.com';
        }

        return (bool) config('paddle.sandbox', true)
            ? 'https://sandbox-api.paddle.com'
            : 'https://api.paddle.com';
    }

    private function isSandboxKey(string $key): bool
    {
        return str_starts_with($key, 'test_');
    }

    private function keyPrefix(string $key): ?string
    {
        if ($key === '') {
            return null;
        }

        return strtok($key, '_').'_';
    }

    private function parseRequestException(RequestException $e): ?string
    {
        $body = $e->response?->json();
        if (! is_array($body)) {
            return null;
        }

        $error = $body['error'] ?? null;
        if (! is_array($error)) {
            return null;
        }

        $detail = trim((string) ($error['detail'] ?? ''));
        $code = trim((string) ($error['code'] ?? ''));

        if ($detail !== '' && $code !== '') {
            return "Paddle ({$code}): {$detail}";
        }

        if ($detail !== '') {
            return "Paddle: {$detail}";
        }

        return null;
    }
}
