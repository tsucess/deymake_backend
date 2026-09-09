<?php

namespace App\Services\Payments;

use App\Models\Payment;

/**
 * Payment gateway contract.
 *
 * Abstracts the payment provider so the application depends on a stable surface
 * rather than a concrete SDK. The default binding is {@see PaystackGateway}; tests
 * exercise the real implementation against a faked HTTP client.
 */
interface PaymentGatewayContract
{
    /**
     * Provider identifier (e.g. "paystack").
     */
    public function name(): string;

    /**
     * Whether the gateway has the credentials required to talk to the provider.
     */
    public function isConfigured(): bool;

    /**
     * Initialize a transaction with the provider.
     *
     * @return array{providerReference: ?string, authorizationUrl: ?string, raw: array<string, mixed>}
     */
    public function initialize(Payment $payment, ?string $callbackUrl = null): array;

    /**
     * Verify a transaction with the provider by reference.
     *
     * @return array{status: string, amount: ?int, currency: ?string, channel: ?string, fees: ?int, paidAt: ?string, providerReference: ?string, gatewayResponse: ?string, raw: array<string, mixed>}
     */
    public function verify(string $reference): array;

    /**
     * Request a refund for a transaction.
     *
     * @return array{status: string, raw: array<string, mixed>}
     */
    public function refund(string $reference, ?int $amount = null): array;

    /**
     * Validate an inbound webhook signature against the raw request body.
     */
    public function verifyWebhookSignature(string $rawBody, ?string $signature): bool;
}
