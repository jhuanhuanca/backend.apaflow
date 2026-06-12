<?php

namespace App\Services\SaaS;

use App\Enums\DocumentBillingStatus;
use App\Enums\PaymentChannel;
use App\Enums\PaymentFlow;
use App\Enums\PaymentStatus;
use App\Enums\UserPlan;
use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payments\PaddleBillingService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Pagos simulados (demo) y vinculación pago ↔ documento / suscripción.
 * PRO solo se activa vía PaymentConfirmationService tras status paid.
 */
class BillingService
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly PaymentConfirmationService $confirmation,
        private readonly PaddleBillingService $paddle,
    ) {}

    public function validateDemoCardPayload(array $data, PaymentChannel $channel): void
    {
        if ($channel !== PaymentChannel::Card) {
            return;
        }

        $digits = preg_replace('/\D/', '', (string) ($data['card_number'] ?? ''));
        if (strlen($digits) < 12 || strlen($digits) > 19) {
            throw ValidationException::withMessages([
                'card_number' => ['Número inválido'],
            ]);
        }

        $exp = trim((string) ($data['card_exp'] ?? ''));
        if ($exp === '' || ! preg_match('/^\d{2}\/\d{2}$/', $exp)) {
            throw ValidationException::withMessages([
                'card_exp' => ['Formato inválido'],
            ]);
        }

        $cvc = preg_replace('/\D/', '', (string) ($data['card_cvc'] ?? ''));
        if (strlen($cvc) < 3 || strlen($cvc) > 4) {
            throw ValidationException::withMessages([
                'card_cvc' => ['Inválido'],
            ]);
        }
    }

    /**
     * Paso 1: crear intención de pago Pro (usuario permanece FREE).
     */
    public function initiateProSubscription(User $user): Payment
    {
        Payment::query()
            ->where('user_id', $user->id)
            ->where('status', PaymentStatus::Pending->value)
            ->where('metadata->flow', PaymentFlow::ProSubscription->value)
            ->update(['status' => PaymentStatus::Canceled->value]);

        return Payment::query()->create([
            'user_id' => $user->id,
            'amount_cents' => $this->subscriptions->proSubscriptionPriceCents(),
            'currency' => config('saas.pricing.currency', 'USD'),
            'status' => PaymentStatus::Pending->value,
            'provider' => 'demo',
            'channel' => PaymentChannel::Card,
            'external_id' => 'pro_pending_'.Str::uuid()->toString(),
            'metadata' => [
                'demo' => true,
                'flow' => PaymentFlow::ProSubscription->value,
                'plan' => UserPlan::Pro->value,
            ],
        ]);
    }

    /**
     * Paso 1 (Paddle): intención Pro + transacción checkout.
     *
     * @return array{payment: Payment, checkout: array<string, mixed>}
     */
    public function initiateProSubscriptionWithPaddle(User $user): array
    {
        Payment::query()
            ->where('user_id', $user->id)
            ->where('status', PaymentStatus::Pending->value)
            ->where('metadata->flow', PaymentFlow::ProSubscription->value)
            ->update(['status' => PaymentStatus::Canceled->value]);

        $payment = Payment::query()->create([
            'user_id' => $user->id,
            'amount_cents' => $this->subscriptions->proSubscriptionPriceCents(),
            'currency' => config('saas.pricing.currency', 'USD'),
            'status' => PaymentStatus::Pending->value,
            'provider' => 'paddle',
            'channel' => PaymentChannel::Card,
            'external_id' => null,
            'metadata' => [
                'flow' => PaymentFlow::ProSubscription->value,
                'plan' => UserPlan::Pro->value,
                'paddle_price_id' => config('paddle.prices.pro_subscription'),
            ],
        ]);

        try {
            $transaction = $this->paddle->createTransaction(
                $user,
                $payment,
                (string) config('paddle.prices.pro_subscription'),
            );
        } catch (\Throwable $e) {
            $payment->forceFill(['status' => PaymentStatus::Canceled->value])->save();

            throw $e;
        }

        $payment->forceFill([
            'external_id' => (string) $transaction['id'],
            'metadata' => array_merge($payment->metadata ?? [], [
                'paddle_transaction_id' => $transaction['id'],
            ]),
        ])->save();

        return [
            'payment' => $payment->fresh(),
            'checkout' => $this->paddle->checkoutPayloadFromTransaction($transaction),
        ];
    }

    /**
     * Paso 1 (Paddle): checkout post-registro con plan Pro.
     *
     * @return array{payment: Payment, checkout: array<string, mixed>}
     */
    public function initiateRegistrationCheckoutWithPaddle(User $user): array
    {
        if ($user->registration_checkout_completed_at !== null) {
            throw new HttpResponseException(response()->json([
                'message' => 'El acceso ya está desbloqueado.',
                'code' => 'CHECKOUT_ALREADY_COMPLETED',
            ], 409));
        }

        Payment::query()
            ->where('user_id', $user->id)
            ->where('status', PaymentStatus::Pending->value)
            ->where('metadata->flow', PaymentFlow::RegistrationCheckout->value)
            ->update(['status' => PaymentStatus::Canceled->value]);

        $payment = Payment::query()->create([
            'user_id' => $user->id,
            'amount_cents' => $this->subscriptions->proSubscriptionPriceCents(),
            'currency' => config('saas.pricing.currency', 'USD'),
            'status' => PaymentStatus::Pending->value,
            'provider' => 'paddle',
            'channel' => PaymentChannel::Card,
            'external_id' => null,
            'metadata' => [
                'flow' => PaymentFlow::RegistrationCheckout->value,
                'plan' => UserPlan::Pro->value,
                'paddle_price_id' => config('paddle.prices.pro_subscription'),
            ],
        ]);

        try {
            $transaction = $this->paddle->createTransaction(
                $user,
                $payment,
                (string) config('paddle.prices.pro_subscription'),
            );
        } catch (\Throwable $e) {
            $payment->forceFill(['status' => PaymentStatus::Canceled->value])->save();

            throw $e;
        }

        $payment->forceFill([
            'external_id' => (string) $transaction['id'],
            'metadata' => array_merge($payment->metadata ?? [], [
                'paddle_transaction_id' => $transaction['id'],
            ]),
        ])->save();

        return [
            'payment' => $payment->fresh(),
            'checkout' => $this->paddle->checkoutPayloadFromTransaction($transaction),
        ];
    }

    /**
     * Activa cuenta gratuita (pay-per-download) sin pasarela.
     */
    public function completeFreeRegistrationCheckout(User $user): User
    {
        if ($user->registration_checkout_completed_at !== null) {
            return $user->fresh();
        }

        $this->subscriptions->completeFreeRegistration($user);

        return $user->fresh();
    }

    /**
     * Paso 1 (Paddle): pago por documento + checkout.
     *
     * @return array{payment: Payment, checkout: array<string, mixed>, document: Document}
     */
    public function initiateDocumentPaymentWithPaddle(User $user, Document $document): array
    {
        if ($document->user_id !== $user->id) {
            abort(404);
        }

        if ($this->subscriptions->hasUnlimitedDocuments($user)) {
            throw new HttpResponseException(response()->json([
                'message' => 'Tu plan no requiere pago por documento.',
                'code' => 'ALREADY_PRO',
            ], 409));
        }

        if ($document->billing_status !== DocumentBillingStatus::PendingPayment) {
            throw new HttpResponseException(response()->json([
                'message' => 'Este documento no está pendiente de pago.',
                'code' => 'DOCUMENT_NOT_PENDING',
            ], 409));
        }

        Payment::query()
            ->where('user_id', $user->id)
            ->where('document_id', $document->id)
            ->where('status', PaymentStatus::Pending->value)
            ->update(['status' => PaymentStatus::Canceled->value]);

        $payment = Payment::query()->create([
            'user_id' => $user->id,
            'document_id' => $document->id,
            'amount_cents' => $this->subscriptions->documentPriceCents(),
            'currency' => config('saas.pricing.currency', 'USD'),
            'status' => PaymentStatus::Pending->value,
            'provider' => 'paddle',
            'channel' => PaymentChannel::Card,
            'external_id' => null,
            'metadata' => [
                'flow' => PaymentFlow::DocumentCheckout->value,
                'document_id' => $document->id,
                'paddle_price_id' => config('paddle.prices.document_checkout'),
            ],
        ]);

        try {
            $transaction = $this->paddle->createTransaction(
                $user,
                $payment,
                (string) config('paddle.prices.document_checkout'),
            );
        } catch (\Throwable $e) {
            $payment->forceFill(['status' => PaymentStatus::Canceled->value])->save();

            throw $e;
        }

        $payment->forceFill([
            'external_id' => (string) $transaction['id'],
            'metadata' => array_merge($payment->metadata ?? [], [
                'paddle_transaction_id' => $transaction['id'],
            ]),
        ])->save();

        return [
            'payment' => $payment->fresh(),
            'checkout' => $this->paddle->checkoutPayloadFromTransaction($transaction),
            'document' => $document->fresh(['logs']),
        ];
    }

    /**
     * Finaliza pago de documento tras webhook Paddle o confirmación manual.
     */
    public function finalizeDocumentPayment(Payment $payment): Document
    {
        $document = Document::query()->findOrFail((int) ($payment->document_id ?? $payment->metadata['document_id'] ?? 0));

        if ($document->billing_status === DocumentBillingStatus::Paid) {
            return $document->fresh(['logs']);
        }

        $document->forceFill([
            'payment_id' => $payment->id,
            'billing_status' => DocumentBillingStatus::Paid,
        ])->save();

        $document->addLog('Pago confirmado. Documento encolado para formateo APA 7.');
        ProcessDocumentJob::dispatch($document->fresh());

        return $document->fresh(['logs']);
    }

    /**
     * Sincroniza pago Paddle pendiente de un documento (polling SPA / post-checkout).
     *
     * @return array{synced: bool, document: Document, payment: ?Payment, reason?: string}
     */
    public function syncDocumentPaymentFromPaddle(User $user, Document $document, PaddleWebhookHandler $paddleWebhook): array
    {
        if ($document->user_id !== $user->id) {
            abort(404);
        }

        $document = $document->fresh(['logs']);

        if ($document->billing_status === DocumentBillingStatus::Paid) {
            return [
                'synced' => true,
                'document' => $document,
                'payment' => $document->payment_id
                    ? Payment::query()->find($document->payment_id)
                    : null,
            ];
        }

        $payment = Payment::query()
            ->where('user_id', $user->id)
            ->where('document_id', $document->id)
            ->where('status', PaymentStatus::Pending->value)
            ->where('provider', 'paddle')
            ->orderByDesc('id')
            ->first();

        if (! $payment || ! filled($payment->external_id)) {
            return [
                'synced' => false,
                'document' => $document,
                'payment' => $payment,
                'reason' => 'no_pending_paddle_payment',
            ];
        }

        $synced = $paddleWebhook->syncPendingPayment($payment);

        return [
            'synced' => $synced,
            'document' => $document->fresh(['logs']),
            'payment' => $payment->fresh(),
        ];
    }
    {
        $payment = Payment::query()
            ->where('user_id', $user->id)
            ->whereKey($paymentId)
            ->firstOrFail();

        if ($payment->status !== PaymentStatus::Pending) {
            throw new HttpResponseException(response()->json([
                'message' => 'Este pago ya fue procesado o no está pendiente.',
                'code' => 'PAYMENT_NOT_PENDING',
            ], 409));
        }

        if (($payment->metadata['flow'] ?? '') !== PaymentFlow::ProSubscription->value) {
            throw new HttpResponseException(response()->json([
                'message' => 'El pago no corresponde a una suscripción Pro.',
                'code' => 'INVALID_PAYMENT_FLOW',
            ], 422));
        }

        $this->validateDemoCardPayload($data, $channel);

        return $this->confirmation->confirmPaidAndGrant($payment, [
            'channel' => $channel,
            'provider' => 'demo',
            'external_id' => 'pro_paid_'.Str::uuid()->toString(),
            'metadata' => array_merge($payment->metadata ?? [], [
                'card_last4' => $this->cardLast4($channel, $data),
                'confirmed_via' => 'demo_checkout',
            ]),
        ]);
    }

    /**
     * Checkout post-registro: free (sin cobro) o suscripción Pro tras pago confirmado.
     */
    public function completeRegistrationCheckout(User $user, string $selectedPlan, PaymentChannel $channel, array $data = []): User
    {
        $isPro = in_array($selectedPlan, [UserPlan::Pro->value, 'premium', 'pro'], true);

        if ($isPro) {
            $this->validateDemoCardPayload($data, $channel);
        }

        return DB::transaction(function () use ($user, $selectedPlan, $channel, $data, $isPro) {
            if ($isPro) {
                $pending = Payment::query()->create([
                    'user_id' => $user->id,
                    'amount_cents' => $this->subscriptions->proSubscriptionPriceCents(),
                    'currency' => config('saas.pricing.currency', 'USD'),
                    'status' => PaymentStatus::Pending->value,
                    'provider' => 'demo',
                    'channel' => $channel,
                    'external_id' => 'reg_pro_pending_'.Str::uuid()->toString(),
                    'metadata' => [
                        'demo' => true,
                        'flow' => PaymentFlow::RegistrationCheckout->value,
                        'plan' => UserPlan::Pro->value,
                    ],
                ]);

                $this->confirmation->confirmPaidAndGrant($pending, [
                    'channel' => $channel,
                    'external_id' => 'reg_pro_'.Str::uuid()->toString(),
                    'metadata' => array_merge($pending->metadata ?? [], [
                        'card_last4' => $this->cardLast4($channel, $data),
                        'confirmed_via' => 'registration_checkout',
                    ]),
                ]);
            } else {
                $this->subscriptions->completeFreeRegistration($user);
            }

            if ($user->registration_checkout_completed_at === null) {
                $user->forceFill([
                    'registration_checkout_completed_at' => now(),
                ])->save();
            }

            return $user->fresh();
        });
    }

    /**
     * Pago demo por documento individual (usuarios free). No cambia el plan.
     */
    public function completeDocumentPayment(User $user, Document $document, PaymentChannel $channel, array $data = []): Document
    {
        if ($document->user_id !== $user->id) {
            abort(404);
        }

        if ($this->subscriptions->hasUnlimitedDocuments($user)) {
            return $document;
        }

        if ($document->billing_status !== DocumentBillingStatus::PendingPayment) {
            return $document;
        }

        $this->validateDemoCardPayload($data, $channel);

        return DB::transaction(function () use ($user, $document, $channel, $data) {
            $payment = Payment::query()->create([
                'user_id' => $user->id,
                'document_id' => $document->id,
                'amount_cents' => $this->subscriptions->documentPriceCents(),
                'currency' => config('saas.pricing.currency', 'USD'),
                'status' => PaymentStatus::Pending->value,
                'provider' => 'demo',
                'channel' => $channel,
                'external_id' => 'doc_pending_'.Str::uuid()->toString(),
                'metadata' => [
                    'demo' => true,
                    'flow' => PaymentFlow::DocumentCheckout->value,
                    'document_id' => $document->id,
                ],
            ]);

            $this->confirmation->markPaymentPaid($payment, [
                'external_id' => 'doc_'.Str::uuid()->toString(),
                'metadata' => array_merge($payment->metadata ?? [], [
                    'card_last4' => $this->cardLast4($channel, $data),
                    'confirmed_via' => 'document_checkout',
                ]),
            ]);

            return $this->finalizeDocumentPayment($payment->fresh());
        });
    }

    /**
     * @deprecated Usar initiateProSubscription + confirmProSubscription
     */
    public function upgradeToPro(User $user, PaymentChannel $channel, array $data = []): User
    {
        $payment = $this->initiateProSubscription($user);

        return $this->confirmProSubscription($user, $payment->id, $channel, $data);
    }

    private function cardLast4(PaymentChannel $channel, array $data): ?string
    {
        if ($channel !== PaymentChannel::Card) {
            return null;
        }

        return substr(preg_replace('/\D/', '', (string) ($data['card_number'] ?? '')), -4) ?: null;
    }
}
