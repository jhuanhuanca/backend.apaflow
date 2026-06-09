<?php

namespace App\Http\Controllers\Api;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payments\PaymentProviderResolver;
use App\Services\Payments\PaddleBillingService;
use App\Services\Payments\PaddleWebhookHandler;
use App\Services\SaaS\BillingService;
use App\Services\SaaS\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Enums\PaymentChannel;

class BillingController extends Controller
{
    public function __construct(
        private readonly BillingService $billing,
        private readonly SubscriptionService $subscriptions,
        private readonly PaddleBillingService $paddle,
        private readonly PaddleWebhookHandler $paddleWebhook,
    ) {}

    /**
     * Paso 1: intención de pago Pro (usuario sigue FREE).
     */
    public function initiateProSubscription(Request $request): JsonResponse
    {
        if (PaymentProviderResolver::current() === 'none') {
            return $this->paymentsDisabledResponse();
        }

        /** @var User $user */
        $user = $request->user();

        if (PaymentProviderResolver::isPaddle()) {
            try {
                $result = $this->billing->initiateProSubscriptionWithPaddle($user);
            } catch (\Throwable $e) {
                return response()->json([
                    'message' => 'No se pudo iniciar checkout Paddle.',
                    'code' => 'PADDLE_INIT_FAILED',
                    'detail' => app()->hasDebugModeEnabled() ? $e->getMessage() : null,
                ], 502);
            }

            return response()->json([
                'message' => 'Checkout Paddle creado. Completa el pago para activar Pro.',
                'payment' => $this->paymentPayload($result['payment']),
                'checkout' => $result['checkout'],
                'user' => $this->subscriptions->userPayload($user->fresh()),
            ], 201);
        }

        $payment = $this->billing->initiateProSubscription($user);

        return response()->json([
            'message' => 'Intención de pago creada. Completa el checkout para activar Pro.',
            'payment' => $this->paymentPayload($payment),
            'checkout' => ['provider' => 'demo'],
            'user' => $this->subscriptions->userPayload($user->fresh()),
        ], 201);
    }

    /**
     * Paso 2: confirmar suscripción Pro (solo demo).
     */
    public function confirmProSubscription(Request $request): JsonResponse
    {
        if (! PaymentProviderResolver::isDemo()) {
            return response()->json([
                'message' => 'La confirmación manual no aplica con Paddle. Usa el checkout y espera la confirmación.',
                'code' => 'USE_PADDLE_CHECKOUT',
            ], 409);
        }

        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'payment_id' => ['required', 'integer', 'exists:payments,id'],
            'channel' => ['required', Rule::enum(PaymentChannel::class)],
            'card_number' => ['required_if:channel,'.PaymentChannel::Card->value, 'nullable', 'string'],
            'card_exp' => ['required_if:channel,'.PaymentChannel::Card->value, 'nullable', 'string', 'max:7'],
            'card_cvc' => ['required_if:channel,'.PaymentChannel::Card->value, 'nullable', 'string', 'max:4'],
        ]);

        $channel = $data['channel'] instanceof PaymentChannel
            ? $data['channel']
            : PaymentChannel::from((string) $data['channel']);

        try {
            $user = $this->billing->confirmProSubscription(
                $user,
                (int) $data['payment_id'],
                $channel,
                $data,
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Datos de pago inválidos.',
                'errors' => $e->errors(),
            ], 422);
        }

        return response()->json([
            'message' => 'Pago confirmado. Plan Pro activado.',
            'user' => $this->subscriptions->userPayload($user),
        ]);
    }

    /**
     * Pago por documento (demo) o checkout Paddle.
     */
    public function completeDocumentPayment(Request $request): JsonResponse
    {
        if (PaymentProviderResolver::current() === 'none') {
            return $this->paymentsDisabledResponse();
        }

        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'document_id' => ['required', 'integer', 'exists:documents,id'],
            'channel' => [Rule::requiredIf(PaymentProviderResolver::isDemo()), Rule::enum(PaymentChannel::class)],
            'card_number' => ['required_if:channel,'.PaymentChannel::Card->value, 'nullable', 'string'],
            'card_exp' => ['required_if:channel,'.PaymentChannel::Card->value, 'nullable', 'string', 'max:7'],
            'card_cvc' => ['required_if:channel,'.PaymentChannel::Card->value, 'nullable', 'string', 'max:4'],
        ]);

        $document = Document::query()
            ->where('user_id', $user->id)
            ->findOrFail((int) $data['document_id']);

        if (PaymentProviderResolver::isPaddle()) {
            try {
                $result = $this->billing->initiateDocumentPaymentWithPaddle($user, $document);
            } catch (\Throwable $e) {
                return response()->json([
                    'message' => 'No se pudo iniciar checkout Paddle para el documento.',
                    'code' => 'PADDLE_INIT_FAILED',
                    'detail' => app()->hasDebugModeEnabled() ? $e->getMessage() : null,
                ], 502);
            }

            return response()->json([
                'message' => 'Checkout Paddle creado. Completa el pago para procesar el documento.',
                'payment' => $this->paymentPayload($result['payment']),
                'checkout' => $result['checkout'],
                'document' => $result['document'],
                'user' => $this->subscriptions->userPayload($user->fresh()),
            ], 201);
        }

        $channel = $data['channel'] instanceof PaymentChannel
            ? $data['channel']
            : PaymentChannel::from((string) $data['channel']);

        try {
            $document = $this->billing->completeDocumentPayment($user, $document, $channel, $data);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Datos de pago inválidos.',
                'errors' => $e->errors(),
            ], 422);
        }

        return response()->json([
            'message' => 'Pago confirmado. El documento se está procesando.',
            'document' => $document,
            'user' => $this->subscriptions->userPayload($user->fresh()),
        ]);
    }

    public function paymentStatus(Request $request, Payment $payment): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($payment->user_id !== $user->id) {
            abort(404);
        }

        if (
            PaymentProviderResolver::isPaddle()
            && $payment->status === PaymentStatus::Pending
            && filled($payment->external_id)
        ) {
            $transaction = $this->paddle->fetchTransaction((string) $payment->external_id);
            if (is_array($transaction) && ($transaction['status'] ?? '') === 'completed') {
                $this->paddleWebhook->completePaymentFromTransaction($transaction);
                $payment = $payment->fresh();
            }
        }

        $payload = [
            'payment' => $this->paymentPayload($payment->fresh()),
            'user' => $this->subscriptions->userPayload($user->fresh()),
        ];

        if ($payment->document_id) {
            $payload['document'] = Document::query()->find($payment->document_id);
        }

        return response()->json($payload);
    }

    /**
     * @deprecated Usar initiateProSubscription + confirmProSubscription
     */
    public function upgradeToPro(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Endpoint obsoleto. Usa POST /api/billing/pro-subscription/initiate y /confirm.',
            'code' => 'USE_PRO_SUBSCRIPTION_FLOW',
        ], 410);
    }

    private function paymentsDisabledResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'Los pagos no están configurados.',
            'code' => 'PAYMENTS_DISABLED',
        ], 503);
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentPayload(Payment $payment): array
    {
        $flow = $payment->metadata['flow'] ?? null;
        $formatted = config('saas.pricing.pro_monthly_formatted', '$5.99');
        if ($flow === 'document_checkout') {
            $formatted = config('saas.pricing.free_per_document_formatted', '$0.99');
        }

        return [
            'id' => $payment->id,
            'status' => $payment->status instanceof PaymentStatus
                ? $payment->status->value
                : $payment->status,
            'amount_cents' => $payment->amount_cents,
            'currency' => $payment->currency,
            'provider' => $payment->provider,
            'formatted_price' => $formatted,
            'flow' => $flow,
            'external_id' => $payment->external_id,
        ];
    }
}
