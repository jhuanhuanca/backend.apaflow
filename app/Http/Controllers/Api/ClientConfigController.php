<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Payments\PaymentProviderResolver;
use App\Services\Payments\PaddleBillingService;
use App\Services\SaaS\AdsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Schema;

/**
 * Configuración pública para clientes (Vue SPA, Flutter). Sin autenticación.
 */
class ClientConfigController extends Controller
{
    public function __invoke(AdsService $ads): JsonResponse
    {
        $guestReady = Schema::hasColumn('documents', 'guest_fingerprint');

        return response()->json([
            'api_version' => 1,
            'brand' => config('saas.brand'),
            'guest' => [
                'max_uploads' => (int) config('saas.guest_max_uploads', 1),
                'max_free_downloads' => (int) config('saas.guest_max_free_downloads', 1),
                'schema_ready' => $guestReady,
            ],
            'upload' => [
                'max_file_kb' => (int) config('saas.upload.max_file_kb', 51200),
                'allowed_extensions' => config('saas.upload.allowed_mimes', ['docx']),
            ],
            'defaults' => config('saas.defaults'),
            'pricing' => [
                'currency' => config('saas.pricing.currency', 'USD'),
                'free_per_document_cents' => (int) config('saas.pricing.free_per_document_cents', 99),
                'free_per_document_formatted' => config('saas.pricing.free_per_document_formatted', '$0.99'),
                'pro_monthly_cents' => (int) config('saas.pricing.pro_monthly_cents', 599),
                'pro_monthly_formatted' => config('saas.pricing.pro_monthly_formatted', '$5.99'),
            ],
            'plans' => config('saas.plans'),
            'apa_defaults' => config('saas.apa_defaults'),
            'payments' => [
                'provider' => PaymentProviderResolver::current(),
                'demo_checkout_enabled' => PaymentProviderResolver::demoEnabled(),
                'paddle' => [
                    'server_enabled' => PaddleBillingService::isServerConfigured(),
                    'checkout_ready' => PaddleBillingService::isClientConfigured(),
                    'enabled' => PaddleBillingService::isClientConfigured(),
                    'client_token' => filled(config('paddle.client_token'))
                        ? (string) config('paddle.client_token')
                        : null,
                    'environment' => PaddleBillingService::isServerConfigured() && (
                        str_starts_with((string) config('paddle.api_key'), 'test_')
                        || ((bool) config('paddle.sandbox', true) && ! str_starts_with((string) config('paddle.api_key'), 'live_'))
                    ) ? 'sandbox' : 'production',
                ],
            ],
            'auth' => [
                'spa' => 'cookie+csrf',
                'mobile' => 'bearer',
                'mobile_device_name' => 'apa-flow-mobile',
            ],
            'ads' => $ads->publicConfig(),
        ]);
    }
}
