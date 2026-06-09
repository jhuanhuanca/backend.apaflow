<?php

namespace App\Http\Controllers\Api;

use App\Enums\PaymentChannel;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SaaS\BillingService;
use App\Services\SaaS\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Checkout simulado post-registro (tarjeta o QR). Sin cargo real.
 * Activo cuando payments.demo_upgrade_enabled es true (ver config/payments.php).
 */
class BillingDemoController extends Controller
{
    public function __construct(
        private readonly BillingService $billing,
        private readonly SubscriptionService $subscriptions,
    ) {}

    /**
     * Estado para el SPA: si debe mostrar la pantalla de pago simulado.
     */
    public function checkoutStatus(Request $request): JsonResponse
    {
        $user = $request->user();
        $demo = $this->demoEnabled();

        return response()->json([
            'demo_enabled' => $demo,
            'needs_checkout' => $demo && $user instanceof User && $user->registration_checkout_completed_at === null,
            'user' => $this->subscriptions->userPayload($user),
            'pricing' => [
                'document' => config('saas.pricing.free_per_document_formatted', '$0.99'),
                'pro_monthly' => config('saas.pricing.pro_monthly_formatted', '$5.99'),
            ],
        ]);
    }

    public function completeRegistrationCheckout(Request $request): JsonResponse
    {
        if (! $this->demoEnabled()) {
            return response()->json([
                'message' => 'El checkout de demostración está desactivado.',
                'code' => 'DEMO_DISABLED',
            ], 403);
        }

        /** @var User $user */
        $user = $request->user();

        if ($user->registration_checkout_completed_at !== null) {
            return response()->json([
                'message' => 'El acceso ya está desbloqueado.',
                'user' => $this->subscriptions->userPayload($user->fresh()),
            ]);
        }

        $data = $request->validate([
            'plan' => ['nullable', 'string', 'in:pay-per-download,premium,pro,free'],
            'channel' => ['required', Rule::enum(PaymentChannel::class)],
            'card_number' => ['required_if:plan,premium,pro', 'nullable', 'string'],
            'card_exp' => ['required_if:plan,premium,pro', 'nullable', 'string', 'max:7'],
            'card_cvc' => ['required_if:plan,premium,pro', 'nullable', 'string', 'max:4'],
        ]);

        $selectedPlan = (string) ($data['plan'] ?? 'pay-per-download');
        $isProPlan = in_array($selectedPlan, ['premium', 'pro'], true);

        if ($isProPlan) {
            $request->validate([
                'card_number' => ['required', 'string'],
                'card_exp' => ['required', 'string', 'max:7'],
                'card_cvc' => ['required', 'string', 'max:4'],
            ]);
        }

        $channel = $data['channel'] instanceof PaymentChannel ? $data['channel'] : PaymentChannel::from((string) $data['channel']);

        try {
            $user = $this->billing->completeRegistrationCheckout($user, $selectedPlan, $channel, $data);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Datos de pago inválidos.',
                'errors' => $e->errors(),
            ], 422);
        }

        $isPro = $isProPlan;

        return response()->json([
            'message' => $isPro
                ? 'Plan Pro activado. Ya puedes usar el panel.'
                : 'Cuenta activada. Pagarás $0.99 por cada documento que conviertas.',
            'user' => $this->subscriptions->userPayload($user),
        ]);
    }

    private function demoEnabled(): bool
    {
        return (bool) config('payments.demo_upgrade_enabled', false);
    }
}
