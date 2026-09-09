<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Models\MerchOrder;
use App\Models\Payment;
use App\Services\Payments\PaymentGatewayContract;
use App\Services\Payments\PaymentGatewayException;
use App\Services\Payments\MerchOrderPaymentService;
use App\Support\SupportedLocales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Payment controller (checkout).
 *
 * User-facing entry points that initialize a provider transaction and verify its
 * outcome. Initialization always creates a `pending` payment; a payment is only
 * ever promoted to `successful` from the provider's authoritative verify result
 * (or a verified webhook) — never from client-supplied input.
 *
 * Routes: authenticated group in routes/api.php (/payments/*).
 */
class PaymentController extends Controller
{
    private const PURPOSES = ['coin_purchase', 'wallet_topup', 'membership', 'merch_order', 'general'];

    public function initialize(Request $request, PaymentGatewayContract $gateway): JsonResponse
    {
        SupportedLocales::apply($request);

        if (! $gateway->isConfigured()) {
            return response()->json(['message' => __('messages.payment.not_configured')], 503);
        }

        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:100'],
            'purpose' => ['nullable', Rule::in(self::PURPOSES)],
            'currency' => ['nullable', 'string', 'size:3'],
            'email' => ['nullable', 'email'],
            'callbackUrl' => ['nullable', 'url'],
            'metadata' => ['nullable', 'array'],
            'purposeId' => ['nullable', 'integer'],
        ]);

        $user = $request->user();

        if (($data['purpose'] ?? null) === 'merch_order') {
            $order = MerchOrder::query()->findOrFail($data['purposeId'] ?? 0);
            abort_if($order->buyer_id !== $user?->id, 403);
            abort_if($order->status !== 'pending' || (int) $order->total_amount !== (int) $data['amount'], 422);
        }

        $payment = Payment::query()->create([
            'reference' => $this->uniqueReference(),
            'provider' => $gateway->name(),
            'user_id' => $user?->id,
            'email' => $data['email'] ?? $user?->email,
            'purpose' => $data['purpose'] ?? 'general',
            'purpose_id' => $data['purposeId'] ?? null,
            'amount' => $data['amount'],
            'currency' => strtoupper($data['currency'] ?? 'NGN'),
            'status' => 'pending',
            'metadata' => $data['metadata'] ?? null,
        ]);

        try {
            $result = $gateway->initialize(
                $payment,
                $data['callbackUrl'] ?? config('services.paystack.callback_url')
            );
        } catch (PaymentGatewayException $exception) {
            $payment->status = 'failed';
            $payment->gateway_response = $exception->getMessage();
            $payment->save();

            throw ValidationException::withMessages([
                'gateway' => [__('messages.payment.gateway_error').' '.$exception->getMessage()],
            ]);
        }

        $payment->provider_reference = $result['providerReference'] ?? $payment->reference;
        $payment->authorization_url = $result['authorizationUrl'] ?? null;
        $payment->save();

        return response()->json([
            'message' => __('messages.payment.initialized'),
            'data' => [
                'payment' => new PaymentResource($payment),
                'authorizationUrl' => $payment->authorization_url,
                'reference' => $payment->reference,
            ],
        ], 201);
    }

    public function verify(Request $request, PaymentGatewayContract $gateway, MerchOrderPaymentService $merchOrderPayments, string $reference): JsonResponse
    {
        SupportedLocales::apply($request);

        $payment = Payment::query()
            ->where('reference', $reference)
            ->where('user_id', $request->user()?->id)
            ->firstOrFail();

        try {
            $result = $gateway->verify($payment->provider_reference ?: $payment->reference);
        } catch (PaymentGatewayException $exception) {
            throw ValidationException::withMessages([
                'gateway' => [__('messages.payment.verification_failed').' '.$exception->getMessage()],
            ]);
        }

        $payment = DB::transaction(function () use ($payment, $result): Payment {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            $locked->status = $result['status'];
            $locked->provider_reference = $result['providerReference'] ?? $locked->provider_reference;
            $locked->channel = $result['channel'] ?? $locked->channel;
            $locked->gateway_response = $result['gatewayResponse'] ?? $locked->gateway_response;

            if (isset($result['fees'])) {
                $locked->fees = (int) $result['fees'];
            }

            if ($result['status'] === 'successful') {
                $locked->paid_at = $locked->paid_at
                    ?? ($result['paidAt'] ? Carbon::parse($result['paidAt']) : now());
            }

            $locked->save();

            return $locked;
        });

        $merchOrderPayments->settle($payment);

        return response()->json([
            'message' => __('messages.payment.verified'),
            'data' => ['payment' => new PaymentResource($payment)],
        ]);
    }

    private function uniqueReference(): string
    {
        do {
            $reference = 'DMK_'.strtoupper(Str::random(20));
        } while (Payment::query()->where('reference', $reference)->exists());

        return $reference;
    }
}
