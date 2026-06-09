<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Enums\PaymentChannel;
use App\Enums\PaymentFlow;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\SaaS\PaymentConfirmationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminPaymentController extends Controller
{
    public function __construct(
        private readonly PaymentConfirmationService $confirmation,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $q = Payment::query()->with('user')->orderByDesc('id');

        if ($request->filled('user_id')) {
            $q->where('user_id', $request->integer('user_id'));
        }

        return response()->json($q->paginate($request->integer('per_page', 25)));
    }

    public function store(Request $request): JsonResponse
    {
        $gatewayCodes = array_keys(config('payments.gateways', []));

        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'amount_cents' => ['required', 'integer', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'status' => ['required', 'string', 'in:pending,paid,failed,refunded'],
            'provider' => ['required', 'string', 'max:32', Rule::in($gatewayCodes)],
            'channel' => ['required', Rule::enum(PaymentChannel::class)],
            'external_id' => ['nullable', 'string', 'max:255'],
            'metadata' => ['nullable', 'array'],
            'paid_at' => ['nullable', 'date'],
        ]);

        $allowedChannels = config('payments.gateways.'.$data['provider'].'.channels', ['card', 'qr']);
        $ch = $data['channel'];
        $channelValue = $ch instanceof PaymentChannel ? $ch->value : (string) $ch;
        abort_unless(
            in_array($channelValue, $allowedChannels, true),
            422,
            'El canal seleccionado no está habilitado para esta pasarela.'
        );

        $data['channel'] = $channelValue;

        $payment = Payment::query()->create($data);

        if ($payment->status === PaymentStatus::Paid) {
            $flow = PaymentFlow::tryFrom((string) ($payment->metadata['flow'] ?? ''));
            if (in_array($flow, [PaymentFlow::ProSubscription, PaymentFlow::RegistrationCheckout], true)) {
                $this->confirmation->grantEntitlementsFromPayment($payment);
            }
        }

        return response()->json($payment->load('user'), 201);
    }
}
