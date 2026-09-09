<?php

namespace App\Services\Payments;

use App\Models\Payment;
use Illuminate\Support\Facades\Http;

/**
 * Paystack payment gateway.
 *
 * Talks to the Paystack REST API via the HTTP client. Amounts are exchanged in
 * minor units (kobo), matching the platform-wide money convention. Webhook
 * authenticity is enforced with an HMAC-SHA512 signature over the raw body using
 * the secret key.
 */
class PaystackGateway implements PaymentGatewayContract
{
    public function __construct(
        private readonly string $secretKey,
        private readonly string $baseUrl = 'https://api.paystack.co',
    ) {}

    public function name(): string
    {
        return 'paystack';
    }

    public function isConfigured(): bool
    {
        return trim($this->secretKey) !== '';
    }

    public function initialize(Payment $payment, ?string $callbackUrl = null): array
    {
        $response = $this->client()->post('/transaction/initialize', array_filter([
            'email' => $payment->email,
            'amount' => (int) $payment->amount,
            'reference' => $payment->reference,
            'currency' => $payment->currency,
            'callback_url' => $callbackUrl,
            'metadata' => $payment->metadata ?: null,
        ], fn ($value) => $value !== null));

        if (! $response->successful() || $response->json('status') !== true) {
            throw new PaymentGatewayException(
                (string) ($response->json('message') ?? 'Unable to initialize payment.')
            );
        }

        $data = (array) $response->json('data', []);

        return [
            'providerReference' => $data['reference'] ?? $payment->reference,
            'authorizationUrl' => $data['authorization_url'] ?? null,
            'raw' => $data,
        ];
    }

    public function verify(string $reference): array
    {
        $response = $this->client()->get('/transaction/verify/'.rawurlencode($reference));

        if (! $response->successful() || $response->json('status') !== true) {
            throw new PaymentGatewayException(
                (string) ($response->json('message') ?? 'Unable to verify payment.')
            );
        }

        $data = (array) $response->json('data', []);

        return [
            'status' => $this->mapStatus((string) ($data['status'] ?? '')),
            'amount' => isset($data['amount']) ? (int) $data['amount'] : null,
            'currency' => $data['currency'] ?? null,
            'channel' => $data['channel'] ?? null,
            'fees' => isset($data['fees']) ? (int) $data['fees'] : null,
            'paidAt' => $data['paid_at'] ?? $data['paidAt'] ?? null,
            'providerReference' => $data['reference'] ?? $reference,
            'gatewayResponse' => $data['gateway_response'] ?? null,
            'raw' => $data,
        ];
    }

    public function refund(string $reference, ?int $amount = null): array
    {
        $response = $this->client()->post('/refund', array_filter([
            'transaction' => $reference,
            'amount' => $amount,
        ], fn ($value) => $value !== null));

        if (! $response->successful() || $response->json('status') !== true) {
            throw new PaymentGatewayException(
                (string) ($response->json('message') ?? 'Unable to process refund.')
            );
        }

        $data = (array) $response->json('data', []);

        return [
            'status' => (string) ($data['status'] ?? 'pending'),
            'raw' => $data,
        ];
    }

    public function verifyWebhookSignature(string $rawBody, ?string $signature): bool
    {
        if (! $this->isConfigured() || ! is_string($signature) || $signature === '') {
            return false;
        }

        $expected = hash_hmac('sha512', $rawBody, $this->secretKey);

        return hash_equals($expected, $signature);
    }

    private function client()
    {
        return Http::withToken($this->secretKey)
            ->baseUrl($this->baseUrl)
            ->acceptJson()
            ->asJson()
            ->timeout(30);
    }

    private function mapStatus(string $providerStatus): string
    {
        return match (mb_strtolower($providerStatus)) {
            'success' => 'successful',
            'failed' => 'failed',
            'abandoned' => 'abandoned',
            'reversed', 'refund', 'refunded' => 'refunded',
            'ongoing', 'pending', 'processing' => 'processing',
            default => 'pending',
        };
    }
}
