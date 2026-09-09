<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.paystack.secret_key' => 'sk_test_123',
            'services.paystack.base_url' => 'https://api.paystack.co',
        ]);
    }

    public function test_initialize_creates_a_pending_payment_and_never_marks_success(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        Http::fake([
            'api.paystack.co/transaction/initialize' => Http::response([
                'status' => true,
                'data' => [
                    'authorization_url' => 'https://checkout.paystack.com/xyz',
                    'reference' => 'PSK_INIT_1',
                    'access_code' => 'acc_1',
                ],
            ], 200),
        ]);

        $response = $this->postJson('/api/v1/payments/initialize', [
            'amount' => 500000,
            'purpose' => 'coin_purchase',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.payment.status', 'pending')
            ->assertJsonPath('data.authorizationUrl', 'https://checkout.paystack.com/xyz');

        $this->assertDatabaseHas('payments', [
            'user_id' => $user->id,
            'amount' => 500000,
            'status' => 'pending',
        ]);
    }

    public function test_initialize_returns_service_unavailable_when_not_configured(): void
    {
        config(['services.paystack.secret_key' => '']);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/payments/initialize', ['amount' => 500000])
            ->assertStatus(503);
    }

    public function test_verify_promotes_to_successful_only_on_provider_confirmation(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $payment = Payment::factory()->pending()->for($user)->create(['reference' => 'DMK_VERIFY_1']);

        Http::fake([
            'api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => [
                    'status' => 'success',
                    'reference' => 'DMK_VERIFY_1',
                    'amount' => $payment->amount,
                    'currency' => 'NGN',
                    'channel' => 'card',
                    'fees' => 5000,
                    'paid_at' => now()->toIso8601String(),
                    'gateway_response' => 'Approved',
                ],
            ], 200),
        ]);

        $this->getJson('/api/v1/payments/verify/DMK_VERIFY_1')
            ->assertOk()
            ->assertJsonPath('data.payment.status', 'successful');

        $this->assertNotNull($payment->fresh()->paid_at);
    }

    public function test_verify_keeps_payment_unpaid_when_provider_not_successful(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        Payment::factory()->pending()->for($user)->create(['reference' => 'DMK_VERIFY_2']);

        Http::fake([
            'api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => ['status' => 'abandoned', 'reference' => 'DMK_VERIFY_2'],
            ], 200),
        ]);

        $this->getJson('/api/v1/payments/verify/DMK_VERIFY_2')
            ->assertOk()
            ->assertJsonPath('data.payment.status', 'abandoned');

        $this->assertDatabaseHas('payments', ['reference' => 'DMK_VERIFY_2', 'paid_at' => null]);
    }
}
