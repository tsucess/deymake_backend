<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminPaymentApiTest extends TestCase
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

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function fakeVerify(string $providerStatus = 'success', array $overrides = []): void
    {
        Http::fake([
            'api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'message' => 'Verification successful',
                'data' => array_merge([
                    'status' => $providerStatus,
                    'reference' => 'PSK_REF_1',
                    'amount' => 250000,
                    'currency' => 'NGN',
                    'channel' => 'card',
                    'fees' => 3750,
                    'paid_at' => now()->toIso8601String(),
                    'gateway_response' => 'Approved',
                ], $overrides),
            ], 200),
            'api.paystack.co/refund' => Http::response([
                'status' => true,
                'data' => ['status' => 'processed'],
            ], 200),
        ]);
    }

    public function test_non_admin_cannot_list_payments(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/admin/payments')->assertForbidden();
    }

    public function test_index_returns_payments_with_summary(): void
    {
        $this->actingAsAdmin();
        Payment::factory()->count(3)->create();
        Payment::factory()->failed()->create();
        Payment::factory()->refunded()->create();

        $this->getJson('/api/admin/payments')
            ->assertOk()
            ->assertJsonPath('meta.summary.totalPayments', 5)
            ->assertJsonPath('meta.summary.successful', 3)
            ->assertJsonPath('meta.summary.failed', 1)
            ->assertJsonPath('meta.summary.refunded', 1)
            ->assertJsonStructure([
                'data' => ['payments' => [['id', 'reference', 'provider', 'amount', 'status']]],
                'meta' => ['payments', 'summary'],
            ]);
    }

    public function test_index_handles_empty_dataset(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/admin/payments')
            ->assertOk()
            ->assertJsonPath('meta.summary.totalPayments', 0)
            ->assertJsonPath('meta.summary.totalRevenue', 0);
    }

    public function test_index_supports_search_and_filters(): void
    {
        $this->actingAsAdmin();
        $target = Payment::factory()->create([
            'reference' => 'DMK_FINDME_1',
            'provider' => 'paystack',
            'status' => 'successful',
        ]);
        Payment::factory()->create(['reference' => 'DMK_OTHER_2', 'status' => 'failed']);

        $this->getJson('/api/admin/payments?q=FINDME')
            ->assertOk()
            ->assertJsonPath('meta.payments.total', 1)
            ->assertJsonPath('data.payments.0.reference', 'DMK_FINDME_1');

        $this->getJson('/api/admin/payments?status=failed')
            ->assertOk()
            ->assertJsonPath('meta.payments.total', 1);

        $this->getJson('/api/admin/payments?userId='.$target->user_id)
            ->assertOk()
            ->assertJsonPath('meta.payments.total', 1);
    }

    public function test_show_returns_payment_detail(): void
    {
        $this->actingAsAdmin();
        $payment = Payment::factory()->create();

        $this->getJson('/api/admin/payments/'.$payment->id)
            ->assertOk()
            ->assertJsonPath('data.payment.id', $payment->id)
            ->assertJsonStructure(['data' => ['payment', 'webhookEvents']]);
    }

    public function test_admin_verify_promotes_to_successful_only_on_provider_confirmation(): void
    {
        $this->actingAsAdmin();
        $this->fakeVerify('success');
        $payment = Payment::factory()->pending()->create();

        $this->postJson('/api/admin/payments/'.$payment->id.'/verify')
            ->assertOk()
            ->assertJsonPath('data.payment.status', 'successful');

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'successful']);
        $this->assertNotNull($payment->fresh()->paid_at);
    }

    public function test_admin_verify_does_not_mark_successful_when_provider_reports_failure(): void
    {
        $this->actingAsAdmin();
        $this->fakeVerify('failed');
        $payment = Payment::factory()->pending()->create();

        $this->postJson('/api/admin/payments/'.$payment->id.'/verify')
            ->assertOk()
            ->assertJsonPath('data.payment.status', 'failed');

        $this->assertNull($payment->fresh()->paid_at);
    }

    public function test_admin_can_reconcile_payment_to_provider_status(): void
    {
        $this->actingAsAdmin();
        $this->fakeVerify('success');
        $payment = Payment::factory()->pending()->create();

        $this->postJson('/api/admin/payments/'.$payment->id.'/reconcile')
            ->assertOk()
            ->assertJsonPath('data.payment.status', 'successful');

        $this->assertNotNull($payment->fresh()->reconciled_at);
    }

    public function test_admin_can_refund_successful_payment(): void
    {
        $this->actingAsAdmin();
        $this->fakeVerify();
        $payment = Payment::factory()->create(['status' => 'successful']);

        $this->postJson('/api/admin/payments/'.$payment->id.'/refund', ['reason' => 'Customer request'])
            ->assertOk()
            ->assertJsonPath('data.payment.status', 'refunded');

        $this->assertNotNull($payment->fresh()->refunded_at);
    }

    public function test_refund_rejected_for_non_successful_payment(): void
    {
        $this->actingAsAdmin();
        $payment = Payment::factory()->pending()->create();

        $this->postJson('/api/admin/payments/'.$payment->id.'/refund')
            ->assertStatus(422);
    }

    public function test_admin_can_flag_and_clear_payment(): void
    {
        $this->actingAsAdmin();
        $payment = Payment::factory()->create();

        $this->postJson('/api/admin/payments/'.$payment->id.'/review', ['action' => 'flag', 'reason' => 'Chargeback risk'])
            ->assertOk()
            ->assertJsonPath('data.payment.isFlagged', true);

        $this->postJson('/api/admin/payments/'.$payment->id.'/review', ['action' => 'clear'])
            ->assertOk()
            ->assertJsonPath('data.payment.isFlagged', false);
    }

    public function test_admin_can_export_payments_csv(): void
    {
        $this->actingAsAdmin();
        Payment::factory()->count(2)->create();

        $response = $this->get('/api/admin/payments/export');
        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
    }
}
