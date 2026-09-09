<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use App\Services\Payments\PaymentGatewayContract;
use App\Services\Payments\MerchOrderPaymentService;
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
    public function paystack(Request $request, PaymentGatewayContract $gateway, MerchOrderPaymentService $merchOrderPayments): JsonResponse
    {
        $rawBody = $request->getContent();
        $signature = $request->header('x-paystack-signature');

        if (! $gateway->verifyWebhookSignature($rawBody, $signature)) {
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

        try {
            $event = PaymentWebhookEvent::query()->create([
                'provider' => $gateway->name(),
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

        $this->process($eventType, $reference, $eventData, $event, $merchOrderPayments);

        return response()->json(['message' => __('messages.payment.webhook_received')], 200);
    }

    /**
     * @param  array<string, mixed>  $eventData
     */
    private function process(string $eventType, string $reference, array $eventData, PaymentWebhookEvent $event, MerchOrderPaymentService $merchOrderPayments): void
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
        }

        $event->processed_at = now();
        $event->save();
    }
}
