<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FlutterwavePaymentTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET_HASH = 'flw_hash_123';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.flutterwave.secret_key' => 'FLWSECK_TEST-123',
            'services.flutterwave.base_url' => 'https://api.flutterwave.com/v3',
            'services.flutterwave.secret_hash' => self::SECRET_HASH,
        ]);
    }

    public function test_initialize_with_flutterwave_creates_pending_payment_and_returns_checkout_link(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        Http::fake([
            'api.flutterwave.com/v3/payments' => Http::response([
                'status' => 'success',
                'message' => 'Hosted Link',
                'data' => ['link' => 'https://checkout.flutterwave.com/v3/hosted/pay/abc'],
            ], 200),
        ]);

        $this->postJson('/api/payments/initialize', [
            'amount' => 500000,
            'purpose' => 'coin_purchase',
            'provider' => 'flutterwave',
        ])
            ->assertCreated()
            ->assertJsonPath('data.payment.status', 'pending')
            ->assertJsonPath('data.payment.provider', 'flutterwave')
            ->assertJsonPath('data.authorizationUrl', 'https://checkout.flutterwave.com/v3/hosted/pay/abc');

        $this->assertDatabaseHas('payments', [
            'user_id' => $user->id,
            'amount' => 500000,
            'provider' => 'flutterwave',
            'status' => 'pending',
        ]);

        // Flutterwave charges in major units, so 500000 kobo is sent as 5000.
        Http::assertSent(fn ($request) => str_contains($request->url(), '/payments')
            && (float) $request['amount'] === 5000.0
            && $request['tx_ref'] !== null);
    }

    public function test_verify_promotes_flutterwave_payment_on_provider_confirmation(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $payment = Payment::factory()->pending()->for($user)->create([
            'provider' => 'flutterwave',
            'reference' => 'DMK_FLW_1',
        ]);

        Http::fake([
            'api.flutterwave.com/v3/transactions/verify_by_reference*' => Http::response([
                'status' => 'success',
                'data' => [
                    'id' => 1234567,
                    'tx_ref' => 'DMK_FLW_1',
                    'flw_ref' => 'FLW-MOCK-REF',
                    'status' => 'successful',
                    'amount' => 5000,
                    'currency' => 'NGN',
                    'app_fee' => 70,
                    'payment_type' => 'card',
                    'processor_response' => 'Approved',
                    'created_at' => now()->toIso8601String(),
                ],
            ], 200),
        ]);

        $this->getJson('/api/payments/verify/DMK_FLW_1')
            ->assertOk()
            ->assertJsonPath('data.payment.status', 'successful');

        $fresh = $payment->fresh();
        $this->assertNotNull($fresh->paid_at);
        $this->assertSame('1234567', $fresh->provider_reference);
    }

    private function sendWebhook(array $payload, ?string $hash = null)
    {
        return $this->call('POST', '/api/payments/webhook/flutterwave', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_VERIF_HASH' => $hash ?? self::SECRET_HASH,
        ], json_encode($payload));
    }

    private function chargeCompletedPayload(string $reference, int $id = 555): array
    {
        return [
            'event' => 'charge.completed',
            'data' => [
                'id' => $id,
                'tx_ref' => $reference,
                'flw_ref' => 'FLW-HOOK',
                'status' => 'successful',
                'amount' => 2500,
                'currency' => 'NGN',
                'app_fee' => 40,
                'payment_type' => 'card',
                'processor_response' => 'Approved',
                'created_at' => now()->toIso8601String(),
            ],
        ];
    }

    public function test_flutterwave_webhook_with_valid_hash_marks_payment_successful(): void
    {
        $payment = Payment::factory()->pending()->create([
            'provider' => 'flutterwave',
            'reference' => 'DMK_FLW_HOOK',
        ]);

        $this->sendWebhook($this->chargeCompletedPayload('DMK_FLW_HOOK'))->assertOk();

        $fresh = $payment->fresh();
        $this->assertSame('successful', $fresh->status);
        $this->assertNotNull($fresh->paid_at);
        $this->assertSame('555', $fresh->provider_reference);
        $this->assertDatabaseHas('payment_webhook_events', ['provider' => 'flutterwave']);
    }

    public function test_flutterwave_webhook_with_invalid_hash_is_rejected(): void
    {
        $payment = Payment::factory()->pending()->create([
            'provider' => 'flutterwave',
            'reference' => 'DMK_FLW_BAD',
        ]);

        $this->sendWebhook($this->chargeCompletedPayload('DMK_FLW_BAD'), 'wrong-hash')
            ->assertStatus(401);

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertDatabaseCount('payment_webhook_events', 0);
    }
}
