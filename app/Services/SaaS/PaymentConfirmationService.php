<?php

namespace App\Services\SaaS;

use App\Enums\PaymentFlow;
use App\Enums\PaymentStatus;
use App\Enums\UserPlan;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Única capa que puede otorgar PRO tras confirmación de pago (paid).
 * Usado por BillingService, webhooks y admin.
 */
class PaymentConfirmationService
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
    ) {}

    public function markPaymentPaid(Payment $payment, array $attributes = []): Payment
    {
        if ($payment->status === PaymentStatus::Paid) {
            return $payment->fresh();
        }

        if ($payment->status !== PaymentStatus::Pending) {
            $this->fail('El pago no está pendiente de confirmación.', 'PAYMENT_NOT_PENDING', 409);
        }

        $payment->forceFill(array_merge([
            'status' => PaymentStatus::Paid->value,
            'paid_at' => now(),
        ], $attributes))->save();

        return $payment->fresh();
    }

    public function markPaymentFailed(Payment $payment, ?string $reason = null): Payment
    {
        $metadata = $payment->metadata ?? [];
        if ($reason) {
            $metadata['failure_reason'] = $reason;
        }

        $payment->forceFill([
            'status' => PaymentStatus::Failed->value,
            'metadata' => $metadata,
        ])->save();

        return $payment->fresh();
    }

    /**
     * Otorga beneficios según metadata.flow. Solo si payment.status === paid.
     */
    public function grantEntitlementsFromPayment(Payment $payment): void
    {
        $payment->refresh();

        if ($payment->status !== PaymentStatus::Paid) {
            $this->fail('El pago no está confirmado.', 'PAYMENT_NOT_CONFIRMED', 409);
        }

        $flow = PaymentFlow::tryFrom((string) ($payment->metadata['flow'] ?? ''));

        match ($flow) {
            PaymentFlow::ProSubscription, PaymentFlow::RegistrationCheckout => $this->grantProSubscription($payment),
            PaymentFlow::DocumentCheckout => null,
            default => $this->fail('Flujo de pago no reconocido.', 'INVALID_PAYMENT_FLOW', 422),
        };
    }

    /**
     * Confirma pago y otorga PRO en una transacción atómica.
     */
    public function confirmPaidAndGrant(Payment $payment, array $attributes = []): User
    {
        return DB::transaction(function () use ($payment, $attributes) {
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $paid = $this->markPaymentPaid($locked, $attributes);
            $this->grantEntitlementsFromPayment($paid);

            return $paid->user->fresh();
        });
    }

    private function grantProSubscription(Payment $payment): void
    {
        $user = $payment->user;

        if (! $user) {
            $this->fail('Usuario no encontrado para el pago.', 'USER_NOT_FOUND', 404);
        }

        $expectedCents = $this->subscriptions->proSubscriptionPriceCents();
        $provider = (string) ($payment->provider ?? $payment->metadata['provider'] ?? 'demo');

        if ($provider !== 'paddle' && (int) $payment->amount_cents !== $expectedCents) {
            $this->fail('Monto de suscripción Pro inválido.', 'INVALID_SUBSCRIPTION_AMOUNT', 422);
        }

        $this->subscriptions->activateProSubscription($user);

        if (($payment->metadata['flow'] ?? '') === PaymentFlow::RegistrationCheckout->value
            && $user->registration_checkout_completed_at === null) {
            $user->forceFill([
                'registration_checkout_completed_at' => now(),
            ])->save();
        }
    }

    private function fail(string $message, string $code, int $status): never
    {
        throw new HttpResponseException(response()->json([
            'message' => $message,
            'code' => $code,
        ], $status));
    }
}
