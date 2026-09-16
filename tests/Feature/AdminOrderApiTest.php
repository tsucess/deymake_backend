<?php

namespace Tests\Feature;

use App\Models\MerchOrder;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminOrderApiTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_non_admin_cannot_manage_orders(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/admin/orders')->assertForbidden();
    }

    public function test_admin_can_list_filter_and_view_orders(): void
    {
        $this->admin();
        $target = MerchOrder::factory()->create([
            'status' => 'fulfilled',
            'buyer_id' => User::factory()->create(['name' => 'Order Buyer'])->id,
            'creator_id' => User::factory()->create(['name' => 'Order Creator'])->id,
        ]);
        MerchOrder::factory()->create(['status' => 'cancelled']);

        $this->getJson('/api/admin/orders?q=Order%20Buyer&status=fulfilled&per_page=1')
            ->assertOk()
            ->assertJsonPath('data.orders.0.id', $target->id)
            ->assertJsonPath('data.orders.0.paymentStatus', 'paid')
            ->assertJsonPath('data.orders.0.shippingStatus', 'shipped')
            ->assertJsonStructure(['meta' => ['orders', 'summary']]);

        $this->getJson('/api/admin/orders/'.$target->id)
            ->assertOk()
            ->assertJsonPath('data.order.id', $target->id)
            ->assertJsonPath('data.order.buyer.id', $target->buyer_id);
    }

    public function test_admin_cannot_mark_order_paid_without_confirmed_payment(): void
    {
        $this->admin();
        $order = MerchOrder::factory()->create(['status' => 'pending']);

        $this->patchJson('/api/admin/orders/'.$order->id.'/status', ['status' => 'paid'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertDatabaseHas('merch_orders', ['id' => $order->id, 'status' => 'pending']);
    }

    public function test_admin_can_cancel_order_and_restore_inventory_with_audit_log(): void
    {
        $admin = $this->admin();
        $order = MerchOrder::factory()->create(['status' => 'pending', 'quantity' => 2]);
        $inventoryBefore = $order->product->inventory_count;

        $this->postJson('/api/admin/orders/'.$order->id.'/cancel', ['reason' => 'Customer request'])
            ->assertOk()
            ->assertJsonPath('data.order.status', 'cancelled');

        $this->assertDatabaseHas('merch_orders', ['id' => $order->id, 'status' => 'cancelled']);
        $this->assertSame($inventoryBefore + 2, $order->product->fresh()->inventory_count);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.merch_order_cancelled',
            'user_id' => $admin->id,
            'auditable_id' => $order->id,
        ]);
    }

    public function test_admin_can_mark_paid_only_after_successful_payment(): void
    {
        $this->admin();
        $order = MerchOrder::factory()->create(['status' => 'pending']);
        Payment::factory()->create([
            'purpose' => 'merch_order',
            'purpose_id' => $order->id,
            'status' => 'successful',
            'amount' => $order->total_amount,
        ]);

        $this->patchJson('/api/admin/orders/'.$order->id.'/status', ['status' => 'paid'])
            ->assertOk()
            ->assertJsonPath('data.order.status', 'paid');
    }

    public function test_admin_can_export_orders_csv(): void
    {
        $this->admin();
        MerchOrder::factory()->count(2)->create();

        $response = $this->get('/api/admin/orders/export?status=paid');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('PaymentStatus', $response->streamedContent());
    }

    public function test_admin_can_refund_order_restoring_inventory_and_payment(): void
    {
        $admin = $this->admin();
        Http::fake([
            'api.paystack.co/refund' => Http::response([
                'status' => true,
                'data' => ['status' => 'processed'],
            ], 200),
        ]);

        $order = MerchOrder::factory()->create(['status' => 'fulfilled', 'quantity' => 3]);
        $inventoryBefore = $order->product->inventory_count;
        $payment = Payment::factory()->create([
            'purpose' => 'merch_order',
            'purpose_id' => $order->id,
            'status' => 'successful',
            'amount' => $order->total_amount,
        ]);

        $this->postJson('/api/admin/orders/'.$order->id.'/refund', ['reason' => 'Damaged item'])
            ->assertOk()
            ->assertJsonPath('data.order.status', 'refunded');

        $this->assertDatabaseHas('merch_orders', ['id' => $order->id, 'status' => 'refunded']);
        $this->assertSame($inventoryBefore + 3, $order->product->fresh()->inventory_count);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'refunded']);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.merch_order_refunded',
            'user_id' => $admin->id,
            'auditable_id' => $order->id,
        ]);
    }

    public function test_refund_rejected_for_pending_order(): void
    {
        $this->admin();
        $order = MerchOrder::factory()->create(['status' => 'pending']);

        $this->postJson('/api/admin/orders/'.$order->id.'/refund')
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertDatabaseHas('merch_orders', ['id' => $order->id, 'status' => 'pending']);
    }
}
