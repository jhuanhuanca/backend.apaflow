<?php

namespace App\Http\Controllers\Api;

use App\Enums\PaymentChannel;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Payments\PaymentProviderResolver;
use App\Services\SaaS\BillingService;
use App\Services\SaaS\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Checkout post-registro: demo simulado o Paddle (Pro) / activación gratuita.
 */
class BillingDemoController extends Controller
{
    public function __construct(
        private readonly BillingService $billing,
        private readonly SubscriptionService $subscriptions,
    ) {}

    public function checkoutStatus(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'demo_enabled' => $this->demoEnabled(),
            'payment_provider' => PaymentProviderResolver::current(),
            'needs_checkout' => $this->needsCheckout($user),
            'user' => $this->subscriptions->userPayload($user),
            'pricing' => [
                'document' => config('saas.pricing.free_per_document_formatted', '$0.99'),
                'pro_monthly' => config('saas.pricing.pro_monthly_formatted', '$5.99'),
            ],
        ]);
    }

    public function completeRegistrationCheckout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->registration_checkout_completed_at !== null) {
            return response()->json([
                'success' => true,
                'message' => 'El acceso ya está desbloqueado.',
                'user' => $this->subscriptions->userPayload($user->fresh()),
            ]);
        }

        $data = $request->validate([
            'plan' => ['nullable', 'string', 'in:pay-per-download,premium,pro,free'],
            'channel' => [Rule::requiredIf($this->demoEnabled()), Rule::enum(PaymentChannel::class)],
            'card_number' => ['required_if:plan,premium,pro', 'nullable', 'string'],
            'card_exp' => ['required_if:plan,premium,pro', 'nullable', 'string', 'max:7'],
            'card_cvc' => ['required_if:plan,premium,pro', 'nullable', 'string', 'max:4'],
        ]);

        $selectedPlan = (string) ($data['plan'] ?? 'pay-per-download');
        $isProPlan = in_array($selectedPlan, ['premium', 'pro'], true);

        if ($isProPlan && PaymentProviderResolver::isPaddle()) {
            return response()->json([
                'success' => false,
                'error' => 'Para plan Pro usa el checkout Paddle.',
                'message' => 'Para plan Pro usa POST /api/billing/registration-checkout/initiate.',
                'code' => 'USE_PADDLE_CHECKOUT',
            ], 409);
        }

        if (! $isProPlan && PaymentProviderResolver::isPaddle()) {
            $user = $this->billing->completeFreeRegistrationCheckout($user);

            return response()->json([
                'success' => true,
                'message' => 'Cuenta activada. Pagarás $0.99 por cada documento que conviertas.',
                'user' => $this->subscriptions->userPayload($user),
            ]);
        }

        if (! $this->demoEnabled()) {
            return response()->json([
                'success' => false,
                'error' => 'El checkout de demostración está desactivado.',
                'message' => 'El checkout de demostración está desactivado.',
                'code' => 'DEMO_DISABLED',
            ], 403);
        }

        if ($isProPlan) {
            $request->validate([
                'card_number' => ['required', 'string'],
                'card_exp' => ['required', 'string', 'max:7'],
                'card_cvc' => ['required', 'string', 'max:4'],
            ]);
        }

        $channel = $data['channel'] instanceof PaymentChannel
            ? $data['channel']
            : PaymentChannel::from((string) $data['channel']);

        try {
            $user = $this->billing->completeRegistrationCheckout($user, $selectedPlan, $channel, $data);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Datos de pago inválidos.',
                'message' => 'Datos de pago inválidos.',
                'errors' => $e->errors(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $isProPlan
                ? 'Plan Pro activado. Ya puedes usar el panel.'
                : 'Cuenta activada. Pagarás $0.99 por cada documento que conviertas.',
            'user' => $this->subscriptions->userPayload($user),
        ]);
    }

    private function demoEnabled(): bool
    {
        return PaymentProviderResolver::demoEnabled();
    }

    private function needsCheckout(?User $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        if ($user->registration_checkout_completed_at !== null) {
            return false;
        }

        return PaymentProviderResolver::isPaddle() || $this->demoEnabled();
    }
}
