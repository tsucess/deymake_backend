<?php

namespace App\Services\Payments;

use App\Models\Payment;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Payment gateway manager.
 *
 * Resolves the concrete {@see PaymentGatewayContract} for a given provider,
 * building it from configuration on demand. This is what lets the platform run
 * more than one provider side by side (Paystack and Flutterwave): checkout picks
 * a provider, and every later step (verify, refund, webhook) resolves the same
 * provider from the payment's stored `provider` value.
 */
class PaymentGatewayManager
{
    /** Providers this manager knows how to build. */
    public const PROVIDERS = ['paystack', 'flutterwave'];

    public function __construct(private readonly Config $config) {}

    /**
     * The default provider used when a request does not specify one.
     */
    public function default(): string
    {
        return $this->normalize((string) $this->config->get('services.payments.default', 'paystack'));
    }

    /**
     * Resolve a gateway by provider name, falling back to the default provider.
     */
    public function gateway(?string $provider = null): PaymentGatewayContract
    {
        $provider = $this->normalize((string) ($provider ?: $this->default()));

        return match ($provider) {
            'paystack' => $this->paystack(),
            'flutterwave' => $this->flutterwave(),
            default => throw new PaymentGatewayException("Unsupported payment provider [{$provider}]."),
        };
    }

    /**
     * Resolve the gateway that processed (or should process) a payment.
     */
    public function for(Payment $payment): PaymentGatewayContract
    {
        return $this->gateway($payment->provider);
    }

    /**
     * Whether the given provider name is one the platform supports.
     */
    public function supports(?string $provider): bool
    {
        return in_array($this->normalize((string) $provider), self::PROVIDERS, true);
    }

    /**
     * Provider-specific redirect/callback URL for hosted checkout.
     */
    public function callbackUrl(string $provider): ?string
    {
        $url = $this->config->get("services.{$this->normalize($provider)}.callback_url");

        return $url !== null ? (string) $url : null;
    }

    private function paystack(): PaystackGateway
    {
        $config = (array) $this->config->get('services.paystack', []);

        return new PaystackGateway(
            (string) ($config['secret_key'] ?? ''),
            (string) ($config['base_url'] ?? 'https://api.paystack.co'),
        );
    }

    private function flutterwave(): FlutterwaveGateway
    {
        $config = (array) $this->config->get('services.flutterwave', []);

        return new FlutterwaveGateway(
            (string) ($config['secret_key'] ?? ''),
            (string) ($config['base_url'] ?? 'https://api.flutterwave.com/v3'),
            (string) ($config['secret_hash'] ?? ''),
        );
    }

    private function normalize(string $provider): string
    {
        return mb_strtolower(trim($provider));
    }
}
