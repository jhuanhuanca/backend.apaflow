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
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use App\Enums\PaymentChannel;
use Throwable;

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
            } catch (Throwable $e) {
                return $this->paddleErrorResponse($e, $user, 'init_pro_subscription');
            }

            return $this->paddleSuccessResponse(
                $result,
                $user,
                'Checkout Paddle creado. Completa el pago para activar Pro.',
            );
        }

        $payment = $this->billing->initiateProSubscription($user);

        return response()->json([
            'success' => true,
            'checkout_url' => null,
            'message' => 'Intención de pago creada. Completa el checkout para activar Pro.',
            'payment' => $this->paymentPayload($payment),
            'checkout' => ['provider' => 'demo'],
            'user' => $this->subscriptions->userPayload($user->fresh()),
        ], 201);
    }

    /**
     * Checkout Paddle post-registro (plan Pro).
     */
    public function initiateRegistrationCheckout(Request $request): JsonResponse
    {
        if (! PaymentProviderResolver::isPaddle()) {
            return response()->json([
                'success' => false,
                'error' => 'El checkout Paddle no está disponible.',
                'message' => 'El checkout Paddle no está disponible.',
                'code' => 'PADDLE_NOT_CONFIGURED',
            ], 409);
        }

        /** @var User $user */
        $user = $request->user();

        try {
            $result = $this->billing->initiateRegistrationCheckoutWithPaddle($user);
        } catch (Throwable $e) {
            return $this->paddleErrorResponse($e, $user, 'init_registration_checkout');
        }

        return $this->paddleSuccessResponse(
            $result,
            $user,
            'Checkout Paddle creado. Completa el pago para activar tu cuenta Pro.',
        );
    }

    /**
     * Paso 2: confirmar suscripción Pro (solo demo).
     */
    public function confirmProSubscription(Request $request): JsonResponse
    {
        if (! PaymentProviderResolver::isDemo()) {
            return response()->json([
                'success' => false,
                'error' => 'La confirmación manual no aplica con Paddle. Usa el checkout de Paddle.',
                'message' => 'La confirmación manual no aplica con Paddle. Usa el checkout de Paddle.',
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
                'success' => false,
                'error' => 'Datos de pago inválidos.',
                'message' => 'Datos de pago inválidos.',
                'errors' => $e->errors(),
            ], 422);
        }

        return response()->json([
            'success' => true,
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
            } catch (Throwable $e) {
                return $this->paddleErrorResponse($e, $user, 'init_document_payment', [
                    'document_id' => $document->id,
                ]);
            }

            return $this->paddleSuccessResponse(
                $result,
                $user,
                'Checkout Paddle creado. Completa el pago para procesar el documento.',
                $result['document'] ?? null,
            );
        }

        $channel = $data['channel'] instanceof PaymentChannel
            ? $data['channel']
            : PaymentChannel::from((string) $data['channel']);

        try {
            $document = $this->billing->completeDocumentPayment($user, $document, $channel, $data);
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
            'success' => true,
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
        $missing = PaymentProviderResolver::missingConfiguration();
        $message = $missing === []
            ? 'Los pagos no están configurados.'
            : 'Los pagos no están configurados. Faltan: '.implode(', ', $missing).'.';

        return response()->json([
            'success' => false,
            'error' => $message,
            'message' => $message,
            'code' => 'PAYMENTS_DISABLED',
            'missing' => $missing,
            'provider_preference' => config('payments.provider', 'auto'),
        ], 503);
    }

    /**
     * @param  array{payment: Payment, checkout: array<string, mixed>, document?: Document|null}  $result
     */
    private function paddleSuccessResponse(
        array $result,
        User $user,
        string $message,
        ?Document $document = null,
    ): JsonResponse {
        $checkout = $result['checkout'] ?? [];
        $checkoutUrl = $checkout['checkout_url'] ?? null;

        Log::info('paddle.checkout_ready', [
            'user_id' => $user->id,
            'user_email' => $user->email,
            'payment_id' => $result['payment']->id,
            'transaction_id' => $checkout['transaction_id'] ?? null,
            'checkout_url' => $checkoutUrl,
        ]);

        $payload = [
            'success' => true,
            'checkout_url' => $checkoutUrl,
            'message' => $message,
            'payment' => $this->paymentPayload($result['payment']),
            'checkout' => $checkout,
            'user' => $this->subscriptions->userPayload($user->fresh()),
        ];

        $doc = $document ?? ($result['document'] ?? null);
        if ($doc instanceof Document) {
            $payload['document'] = $doc;
        }

        return response()->json($payload, 201);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function paddleErrorResponse(Throwable $e, User $user, string $context, array $extra = []): JsonResponse
    {
        $message = $this->paddle->publicErrorMessage($e);

        Log::error("paddle.{$context}_failed", array_merge([
            'user_id' => $user->id,
            'user_email' => $user->email,
            'message' => $e->getMessage(),
            'public_message' => $message,
            'exception_class' => $e::class,
        ], $extra));

        return response()->json([
            'success' => false,
            'error' => $message,
            'message' => $message,
            'code' => 'PADDLE_INIT_FAILED',
            'detail' => app()->hasDebugModeEnabled() ? $e->getMessage() : null,
        ], 502);
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
