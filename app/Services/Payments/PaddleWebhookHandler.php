<?php

namespace App\Services\Payments;

use App\Enums\PaymentFlow;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\SaaS\BillingService;
use App\Services\SaaS\PaymentConfirmationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaddleWebhookHandler
{
    public function __construct(
        private readonly PaymentConfirmationService $confirmation,
        private readonly BillingService $billing,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): void
    {
        $eventType = (string) ($payload['event_type'] ?? '');
        $data = $payload['data'] ?? null;

        if (! is_array($data)) {
            return;
        }

        if (! in_array($eventType, ['transaction.completed', 'transaction.paid'], true)) {
            return;
        }

        if (($data['status'] ?? '') !== 'completed') {
            return;
        }

        $this->completePaymentFromTransaction($data);
    }

    /**
     * @param  array<string, mixed>  $transaction
     */
    public function completePaymentFromTransaction(array $transaction): void
    {
        $payment = $this->resolvePayment($transaction);

        if (! $payment || $payment->status === PaymentStatus::Paid) {
            return;
        }

        DB::transaction(function () use ($payment, $transaction) {
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === PaymentStatus::Paid) {
                return;
            }

            $paid = $this->confirmation->markPaymentPaid($locked, [
                'provider' => 'paddle',
                'external_id' => (string) ($transaction['id'] ?? $locked->external_id),
                'metadata' => array_merge($locked->metadata ?? [], [
                    'paddle_transaction_id' => $transaction['id'] ?? null,
                    'paddle_event_status' => $transaction['status'] ?? null,
                ]),
            ]);

            $flow = PaymentFlow::tryFrom((string) ($paid->metadata['flow'] ?? ''));

            match ($flow) {
                PaymentFlow::ProSubscription, PaymentFlow::RegistrationCheckout => $this->confirmation->grantEntitlementsFromPayment($paid),
                PaymentFlow::DocumentCheckout => $this->billing->finalizeDocumentPayment($paid),
                default => Log::warning('paddle.unknown_flow', ['payment_id' => $paid->id, 'flow' => $paid->metadata['flow'] ?? null]),
            };
        });
    }

    /**
     * @param  array<string, mixed>  $transaction
     */
    private function resolvePayment(array $transaction): ?Payment
    {
        $custom = $transaction['custom_data'] ?? [];
        $paymentId = isset($custom['payment_id']) ? (int) $custom['payment_id'] : null;

        if ($paymentId) {
            $payment = Payment::query()->find($paymentId);
            if ($payment) {
                return $payment;
            }
        }

        $transactionId = (string) ($transaction['id'] ?? '');

        if ($transactionId !== '') {
            return Payment::query()->where('external_id', $transactionId)->first();
        }

        return null;
    }
}
