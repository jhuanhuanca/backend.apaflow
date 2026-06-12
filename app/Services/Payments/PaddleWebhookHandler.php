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
        private readonly PaddleBillingService $paddle,
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

        $syncEvents = [
            'transaction.completed',
            'transaction.paid',
            'transaction.updated',
        ];

        if (! in_array($eventType, $syncEvents, true)) {
            return;
        }

        if (! $this->paddle->isTransactionSettled($data)) {
            Log::info('paddle.webhook.transaction_not_settled', [
                'event_type' => $eventType,
                'transaction_id' => $data['id'] ?? null,
                'status' => $data['status'] ?? null,
            ]);

            return;
        }

        Log::info('paddle.webhook.processing', [
            'event_type' => $eventType,
            'transaction_id' => $data['id'] ?? null,
            'status' => $data['status'] ?? null,
        ]);

        $this->completePaymentFromTransaction($data);
    }

    /**
     * Reconcilia un pago pendiente consultando Paddle (fallback si el webhook no llegó).
     */
    public function syncPendingPayment(Payment $payment): bool
    {
        if ($payment->status === PaymentStatus::Paid || $payment->external_id === null) {
            return false;
        }

        $transaction = $this->paddle->fetchTransaction((string) $payment->external_id);
        if (! is_array($transaction) || ! $this->paddle->isTransactionSettled($transaction)) {
            return false;
        }

        $this->completePaymentFromTransaction($transaction);

        return $payment->fresh()->status === PaymentStatus::Paid;
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

        if (! $this->paddle->isTransactionSettled($transaction)) {
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
                    'confirmed_via' => 'paddle_transaction_sync',
                ]),
            ]);

            $flow = PaymentFlow::tryFrom((string) ($paid->metadata['flow'] ?? ''));

            match ($flow) {
                PaymentFlow::ProSubscription, PaymentFlow::RegistrationCheckout => $this->confirmation->grantEntitlementsFromPayment($paid),
                PaymentFlow::DocumentCheckout => $this->billing->finalizeDocumentPayment($paid),
                default => Log::warning('paddle.unknown_flow', ['payment_id' => $paid->id, 'flow' => $paid->metadata['flow'] ?? null]),
            };

            Log::info('paddle.payment_completed', [
                'payment_id' => $paid->id,
                'flow' => $paid->metadata['flow'] ?? null,
                'document_id' => $paid->document_id,
                'user_id' => $paid->user_id,
            ]);
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
