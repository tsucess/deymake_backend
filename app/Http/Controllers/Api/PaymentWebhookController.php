<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use App\Http\Controllers\Api\CoinWalletController;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\MerchOrderPaymentService;
use App\Support\UserNotifier;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Payment webhook controller.
 *
 * Receives asynchronous provider notifications. Every request is authenticated by
 * an HMAC signature over the raw body before any processing. Each event is
 * recorded once under a unique (provider, dedupe_key); redeliveries are a no-op,
 * giving exactly-once, idempotent processing. A verified charge success is the
 * authoritative source that promotes a payment to `successful`.
 */
class PaymentWebhookController extends Controller
{
    public function paystack(Request $request, PaymentGatewayManager $gateways, MerchOrderPaymentService $merchOrderPayments, CoinWalletController $coinWallet): JsonResponse
    {
        $gateway = $gateways->gateway('paystack');
        $rawBody = $request->getContent();

        if (! $gateway->verifyWebhookSignature($rawBody, $request->header('x-paystack-signature'))) {
            return response()->json(['message' => __('messages.payment.invalid_signature')], 401);
        }

        $payload = json_decode($rawBody, true);

        if (! is_array($payload) || ! isset($payload['event'])) {
            return response()->json(['message' => __('messages.payment.webhook_received')], 200);
        }

        $eventType = (string) $payload['event'];
        $eventData = (array) ($payload['data'] ?? []);
        $reference = (string) ($eventData['reference'] ?? '');
        $dedupeKey = $eventType.':'.($eventData['id'] ?? $reference ?: uniqid('evt_', true));

        return $this->ingestAndProcess($gateway->name(), $eventType, $reference, $eventData, $dedupeKey, $payload, $merchOrderPayments, $coinWallet);
    }

    public function flutterwave(Request $request, PaymentGatewayManager $gateways, MerchOrderPaymentService $merchOrderPayments, CoinWalletController $coinWallet): JsonResponse
    {
        $gateway = $gateways->gateway('flutterwave');
        $rawBody = $request->getContent();

        if (! $gateway->verifyWebhookSignature($rawBody, $request->header('verif-hash'))) {
            return response()->json(['message' => __('messages.payment.invalid_signature')], 401);
        }

        $payload = json_decode($rawBody, true);
        $providerEvent = is_array($payload) ? (string) ($payload['event'] ?? '') : '';

        if (! is_array($payload) || $providerEvent === '') {
            return response()->json(['message' => __('messages.payment.webhook_received')], 200);
        }

        $data = (array) ($payload['data'] ?? []);
        $reference = (string) ($data['tx_ref'] ?? '');
        $status = mb_strtolower((string) ($data['status'] ?? ''));
        $eventType = $this->flutterwaveEventType($providerEvent, $status);

        // Normalize Flutterwave's payload into the canonical shape process() consumes.
        $eventData = array_filter([
            'reference' => isset($data['id']) ? (string) $data['id'] : ($reference ?: null),
            'channel' => $data['payment_type'] ?? null,
            'gateway_response' => $data['processor_response'] ?? $data['narration'] ?? null,
            'fees' => isset($data['app_fee']) ? (int) round(((float) $data['app_fee']) * 100) : null,
            'paid_at' => $data['created_at'] ?? null,
        ], fn ($value) => $value !== null);

        $dedupeKey = $providerEvent.':'.($data['id'] ?? $reference ?: uniqid('evt_', true));

        return $this->ingestAndProcess($gateway->name(), $eventType, $reference, $eventData, $dedupeKey, $payload, $merchOrderPayments, $coinWallet);
    }

    /**
     * Record the webhook event once (idempotent) and process it. A redelivery is
     * rejected by the unique (provider, dedupe_key) index and treated as a no-op.
     *
     * @param  array<string, mixed>  $eventData
     * @param  array<string, mixed>  $payload
     */
    private function ingestAndProcess(string $provider, string $eventType, string $reference, array $eventData, string $dedupeKey, array $payload, MerchOrderPaymentService $merchOrderPayments, CoinWalletController $coinWallet): JsonResponse
    {
        try {
            $event = PaymentWebhookEvent::query()->create([
                'provider' => $provider,
                'event_type' => $eventType,
                'reference' => $reference ?: null,
                'dedupe_key' => $dedupeKey,
                'signature_valid' => true,
                'payload' => $payload,
            ]);
        } catch (QueryException) {
            // Duplicate delivery: the unique (provider, dedupe_key) index rejected it.
            return response()->json(['message' => __('messages.payment.webhook_already_processed')], 200);
        }

        $this->process($eventType, $reference, $eventData, $event, $merchOrderPayments, $coinWallet);

        return response()->json(['message' => __('messages.payment.webhook_received')], 200);
    }

    /**
     * Translate a Flutterwave event + transaction status into the canonical event
     * vocabulary process() understands (charge.success/charge.failed/refund.processed).
     */
    private function flutterwaveEventType(string $event, string $status): string
    {
        if (str_contains(mb_strtolower($event), 'refund')) {
            return 'refund.processed';
        }

        return match ($status) {
            'successful', 'success' => 'charge.success',
            'failed' => 'charge.failed',
            default => 'charge.pending',
        };
    }

    /**
     * @param  array<string, mixed>  $eventData
     */
    private function process(string $eventType, string $reference, array $eventData, PaymentWebhookEvent $event, MerchOrderPaymentService $merchOrderPayments, CoinWalletController $coinWallet): void
    {
        $payment = $reference !== ''
            ? Payment::query()->where('reference', $reference)->orWhere('provider_reference', $reference)->first()
            : null;

        if ($payment) {
            DB::transaction(function () use ($payment, $eventType, $eventData): void {
                $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);

                if ($eventType === 'charge.success') {
                    $locked->status = 'successful';
                    $locked->channel = $eventData['channel'] ?? $locked->channel;
                    $locked->provider_reference = $eventData['reference'] ?? $locked->provider_reference;
                    $locked->gateway_response = $eventData['gateway_response'] ?? $locked->gateway_response;

                    if (isset($eventData['fees'])) {
                        $locked->fees = (int) $eventData['fees'];
                    }

                    $locked->paid_at = $locked->paid_at
                        ?? (isset($eventData['paid_at']) ? Carbon::parse($eventData['paid_at']) : now());
                } elseif (in_array($eventType, ['refund.processed', 'charge.refunded'], true)) {
                    $locked->status = 'refunded';
                    $locked->refunded_at = $locked->refunded_at ?? now();
                } elseif ($eventType === 'charge.failed') {
                    if ($locked->status !== 'successful') {
                        $locked->status = 'failed';
                        $locked->gateway_response = $eventData['gateway_response'] ?? $locked->gateway_response;
                    }
                }

                $locked->save();
            });

            $event->payment_id = $payment->id;

            $merchOrderPayments->settle($payment->fresh());
            $coinWallet->settlePayment($payment->fresh());
            if ($eventType === 'charge.success' && $payment->user_id) {
                UserNotifier::sendSystem($payment->user_id, 'payment', 'Payment successful', 'Your payment of '.$payment->amount.' '.$payment->currency.' was successful.', ['paymentId' => $payment->id, 'reference' => $payment->reference, 'purpose' => $payment->purpose]);
            } elseif ($eventType === 'charge.failed' && $payment->user_id) {
                UserNotifier::sendSystem($payment->user_id, 'payment', 'Payment failed', 'Your payment could not be completed.', ['paymentId' => $payment->id, 'reference' => $payment->reference, 'purpose' => $payment->purpose]);
            }
        }

        $event->processed_at = now();
        $event->save();
    }
}
