<?php

namespace Tests\Feature;

use App\Models\MerchProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminDiscountApiTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_admin_can_manage_discounts(): void
    {
        $this->admin();

        $response = $this->postJson('/api/v1/admin/discounts', [
            'name' => 'Summer Sale',
            'code' => 'SUMMER10',
            'type' => 'percentage',
            'value' => 10,
            'isActive' => true,
            'usageLimit' => 5,
            'minOrderAmount' => 1000,
            'expiresAt' => now()->addDays(7)->toDateTimeString(),
        ]);

        $response->assertCreated();
        $discountId = $response->json('data.discount.id');

        $this->getJson('/api/v1/admin/discounts')
            ->assertOk()
            ->assertJsonPath('data.discounts.0.code', 'SUMMER10');

        $this->patchJson('/api/v1/admin/discounts/'.$discountId, [
            'name' => 'Summer Sale Updated',
            'value' => 15,
        ])->assertOk();

        $this->postJson('/api/v1/admin/discounts/'.$discountId.'/toggle')
            ->assertOk();

        $this->deleteJson('/api/v1/admin/discounts/'.$discountId)
            ->assertOk();
    }

    public function test_discount_is_applied_during_checkout_when_valid(): void
    {
        $admin = $this->admin();
        $creator = User::factory()->create();
        $buyer = User::factory()->create();
        $product = MerchProduct::factory()->create([
            'creator_id' => $creator->id,
            'price_amount' => 5000,
            'inventory_count' => 5,
        ]);

        $this->postJson('/api/v1/admin/discounts', [
            'name' => 'Creator Promo',
            'code' => 'CREATOR5',
            'type' => 'percentage',
            'value' => 5,
            'isActive' => true,
            'creatorIds' => [$creator->id],
            'minOrderAmount' => 2000,
        ])->assertCreated();

        Sanctum::actingAs($buyer);

        $this->postJson('/api/v1/merch/products/'.$product->id.'/orders', [
            'quantity' => 1,
            'discountCode' => 'CREATOR5',
        ])
            ->assertCreated()
            ->assertJsonPath('data.order.discountCode', 'CREATOR5')
            ->assertJsonPath('data.order.discountAmount', 250)
            ->assertJsonPath('data.order.totalAmount', 4750);
    }
}
