<?php

namespace Tests\Feature;

use App\Models\MerchOrder;
use App\Models\Payment;
use App\Models\RefundRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminRefundApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.paystack.secret_key' => 'sk_test_123',
            'services.paystack.base_url' => 'https://api.paystack.co',
        ]);

        Http::fake([
            'api.paystack.co/refund' => Http::response([
                'status' => true,
                'data' => ['status' => 'processed'],
            ], 200),
        ]);
    }

    private function admin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_non_admin_cannot_list_refunds(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/admin/refunds')->assertForbidden();
    }

    public function test_admin_can_list_and_filter_refund_requests(): void
    {
        $this->admin();

        $buyer = User::factory()->create(['name' => 'Maya Buyer']);
        $order = MerchOrder::factory()->create([
            'buyer_id' => $buyer->id,
            'status' => 'fulfilled',
        ]);

        $target = RefundRequest::factory()->create([
            'order_id' => $order->id,
            'user_id' => $buyer->id,
            'status' => 'pending',
            'reason' => 'Damaged product',
            'amount' => 2500,
        ]);

        RefundRequest::factory()->create([
            'order_id' => MerchOrder::factory()->create()->id,
            'user_id' => User::factory()->create()->id,
            'status' => 'completed',
            'reason' => 'Another issue',
            'amount' => 6000,
        ]);

        $this->getJson('/api/v1/admin/refunds?q=Damaged&status=pending')
            ->assertOk()
            ->assertJsonPath('data.refunds.0.id', $target->id)
            ->assertJsonPath('meta.summary.pending', 1);

        $this->getJson('/api/v1/admin/refunds/'.$target->id)
            ->assertOk()
            ->assertJsonPath('data.refund.id', $target->id)
            ->assertJsonPath('data.refund.order.id', $order->id);
    }

    public function test_admin_can_review_and_process_a_refund_request(): void
    {
        $admin = $this->admin();
        $buyer = User::factory()->create();
        $order = MerchOrder::factory()->create(['buyer_id' => $buyer->id, 'status' => 'fulfilled']);
        Payment::factory()->create([
            'user_id' => $buyer->id,
            'purpose' => 'merch_order',
            'purpose_id' => $order->id,
            'status' => 'successful',
            'provider_reference' => 'PSK-REF-123',
            'amount' => $order->total_amount,
        ]);

        $refund = RefundRequest::factory()->create([
            'order_id' => $order->id,
            'user_id' => $buyer->id,
            'status' => 'pending',
            'amount' => $order->total_amount,
            'reason' => 'Not as advertised',
        ]);

        $this->patchJson('/api/v1/admin/refunds/'.$refund->id, [
            'status' => 'approved',
            'adminNotes' => 'Approved after review',
        ])
            ->assertOk()
            ->assertJsonPath('data.refund.status', 'approved')
            ->assertJsonPath('data.refund.adminNotes', 'Approved after review');

        $this->postJson('/api/v1/admin/refunds/'.$refund->id.'/process')
            ->assertOk()
            ->assertJsonPath('data.refund.status', 'completed');

        $this->assertDatabaseHas('refund_requests', ['id' => $refund->id, 'status' => 'completed']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.refund_request_processed', 'user_id' => $admin->id]);
    }
}
