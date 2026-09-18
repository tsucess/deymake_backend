<?php

namespace App\Services\Payments;

use App\Models\Payment;
use Illuminate\Support\Facades\Http;

/**
 * Flutterwave payment gateway.
 *
 * Talks to the Flutterwave v3 REST API via the HTTP client. Unlike the
 * platform's internal money convention (minor units / kobo), Flutterwave
 * exchanges amounts in major currency units, so amounts are converted at the
 * boundary. Webhook authenticity is enforced by matching the configured secret
 * hash against the `verif-hash` header (a shared-secret equality check, not an
 * HMAC over the body).
 */
class FlutterwaveGateway implements PaymentGatewayContract
{
    public function __construct(
        private readonly string $secretKey,
        private readonly string $baseUrl = 'https://api.flutterwave.com/v3',
        private readonly string $secretHash = '',
    ) {}

    public function name(): string
    {
        return 'flutterwave';
    }

    public function isConfigured(): bool
    {
        return trim($this->secretKey) !== '';
    }

    public function initialize(Payment $payment, ?string $callbackUrl = null): array
    {
        $response = $this->client()->post('/payments', array_filter([
            'tx_ref' => $payment->reference,
            'amount' => $this->minorToMajor((int) $payment->amount),
            'currency' => $payment->currency,
            'redirect_url' => $callbackUrl,
            'customer' => array_filter(['email' => $payment->email]),
            'meta' => $payment->metadata ?: null,
        ], fn ($value) => $value !== null && $value !== []));

        if (! $response->successful() || $response->json('status') !== 'success') {
            throw new PaymentGatewayException(
                (string) ($response->json('message') ?? 'Unable to initialize payment.')
            );
        }

        $data = (array) $response->json('data', []);

        return [
            'providerReference' => $payment->reference,
            'authorizationUrl' => $data['link'] ?? null,
            'raw' => $data,
        ];
    }

    public function verify(string $reference): array
    {
        $response = ctype_digit($reference)
            ? $this->client()->get('/transactions/'.rawurlencode($reference).'/verify')
            : $this->client()->get('/transactions/verify_by_reference', ['tx_ref' => $reference]);

        if (! $response->successful() || $response->json('status') !== 'success') {
            throw new PaymentGatewayException(
                (string) ($response->json('message') ?? 'Unable to verify payment.')
            );
        }

        $data = (array) $response->json('data', []);

        return [
            'status' => $this->mapStatus((string) ($data['status'] ?? '')),
            'amount' => isset($data['amount']) ? $this->majorToMinor($data['amount']) : null,
            'currency' => $data['currency'] ?? null,
            'channel' => $data['payment_type'] ?? null,
            'fees' => isset($data['app_fee']) ? $this->majorToMinor($data['app_fee']) : null,
            'paidAt' => $data['created_at'] ?? null,
            'providerReference' => isset($data['id']) ? (string) $data['id'] : $reference,
            'gatewayResponse' => $data['processor_response'] ?? $data['narration'] ?? null,
            'raw' => $data,
        ];
    }

    public function refund(string $reference, ?int $amount = null): array
    {
        $response = $this->client()->post('/transactions/'.rawurlencode($reference).'/refund', array_filter([
            'amount' => $amount !== null ? $this->minorToMajor($amount) : null,
        ], fn ($value) => $value !== null));

        if (! $response->successful() || $response->json('status') !== 'success') {
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
        if (trim($this->secretHash) === '' || ! is_string($signature) || $signature === '') {
            return false;
        }

        return hash_equals($this->secretHash, $signature);
    }

    private function client()
    {
        return Http::withToken($this->secretKey)
            ->baseUrl($this->baseUrl)
            ->acceptJson()
            ->asJson()
            ->timeout(30);
    }

    private function minorToMajor(int $amount): float
    {
        return round($amount / 100, 2);
    }

    private function majorToMinor(float|int|string $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    private function mapStatus(string $providerStatus): string
    {
        return match (mb_strtolower($providerStatus)) {
            'successful', 'success' => 'successful',
            'failed' => 'failed',
            'cancelled', 'abandoned' => 'abandoned',
            'refunded' => 'refunded',
            'pending', 'processing' => 'processing',
            default => 'pending',
        };
    }
}
