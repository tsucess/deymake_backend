<?php

namespace Tests\Feature;

use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'sk_test_123';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.paystack.secret_key' => self::SECRET]);
    }

    private function sendWebhook(array $payload, ?string $signature = null)
    {
        $body = json_encode($payload);
        $signature ??= hash_hmac('sha512', $body, self::SECRET);

        return $this->call('POST', '/api/payments/webhook/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
        ], $body);
    }

    private function chargeSuccessPayload(string $reference, int $id = 111): array
    {
        return [
            'event' => 'charge.success',
            'data' => [
                'id' => $id,
                'status' => 'success',
                'reference' => $reference,
                'amount' => 250000,
                'currency' => 'NGN',
                'channel' => 'card',
                'fees' => 3750,
                'paid_at' => now()->toIso8601String(),
                'gateway_response' => 'Approved',
            ],
        ];
    }

    public function test_webhook_with_invalid_signature_is_rejected_and_payment_untouched(): void
    {
        $payment = Payment::factory()->pending()->create(['reference' => 'DMK_HOOK_1']);

        $this->sendWebhook($this->chargeSuccessPayload('DMK_HOOK_1'), 'not-a-valid-signature')
            ->assertStatus(401);

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'pending']);
        $this->assertDatabaseCount('payment_webhook_events', 0);
    }

    public function test_webhook_with_missing_signature_is_rejected(): void
    {
        Payment::factory()->pending()->create(['reference' => 'DMK_HOOK_M']);

        $this->call('POST', '/api/payments/webhook/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($this->chargeSuccessPayload('DMK_HOOK_M')))
            ->assertStatus(401);
    }

    public function test_valid_charge_success_webhook_marks_payment_successful(): void
    {
        $payment = Payment::factory()->pending()->create(['reference' => 'DMK_HOOK_2']);

        $this->sendWebhook($this->chargeSuccessPayload('DMK_HOOK_2'))
            ->assertOk();

        $fresh = $payment->fresh();
        $this->assertSame('successful', $fresh->status);
        $this->assertNotNull($fresh->paid_at);
        $this->assertSame(3750, $fresh->fees);
        $this->assertDatabaseCount('payment_webhook_events', 1);
    }

    public function test_duplicate_webhook_delivery_is_processed_only_once(): void
    {
        $payment = Payment::factory()->pending()->create(['reference' => 'DMK_HOOK_3']);
        $payload = $this->chargeSuccessPayload('DMK_HOOK_3', 222);

        $this->sendWebhook($payload)->assertOk();
        $this->sendWebhook($payload)->assertOk()
            ->assertJsonPath('message', __('messages.payment.webhook_already_processed'));

        $this->assertSame('successful', $payment->fresh()->status);
        $this->assertDatabaseCount('payment_webhook_events', 1);
    }
}
