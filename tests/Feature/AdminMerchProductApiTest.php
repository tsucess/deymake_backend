<?php

namespace Tests\Feature;

use App\Models\MerchOrder;
use App\Models\MerchProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminMerchProductApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_non_admin_cannot_list_products(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/admin/products')->assertForbidden();
    }

    public function test_index_returns_products_with_summary(): void
    {
        $this->actingAsAdmin();
        MerchProduct::factory()->count(2)->create();
        MerchProduct::factory()->draft()->create();
        MerchProduct::factory()->outOfStock()->create();

        $this->getJson('/api/admin/products')
            ->assertOk()
            ->assertJsonPath('meta.summary.totalProducts', 4)
            ->assertJsonPath('meta.summary.draftProducts', 1)
            ->assertJsonStructure([
                'data' => ['products'],
                'meta' => ['products', 'summary' => ['totalProducts', 'activeProducts', 'outOfStock']],
            ]);
    }

    public function test_index_handles_empty_dataset(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/admin/products')
            ->assertOk()
            ->assertJsonPath('meta.summary.totalProducts', 0)
            ->assertJsonPath('meta.summary.totalInventory', 0);
    }

    public function test_index_supports_search_and_filters(): void
    {
        $this->actingAsAdmin();
        $creator = User::factory()->create(['username' => 'targetcreator']);
        MerchProduct::factory()->for($creator, 'creator')->create(['name' => 'Signature Hoodie']);
        MerchProduct::factory()->archived()->create(['name' => 'Old Cap']);

        $this->getJson('/api/admin/products?q=Hoodie')
            ->assertOk()
            ->assertJsonPath('meta.products.total', 1)
            ->assertJsonPath('data.products.0.name', 'Signature Hoodie');

        $this->getJson('/api/admin/products?creatorId='.$creator->id)
            ->assertOk()
            ->assertJsonPath('meta.products.total', 1);

        $this->getJson('/api/admin/products?status=archived')
            ->assertOk()
            ->assertJsonPath('meta.products.total', 1)
            ->assertJsonPath('data.products.0.name', 'Old Cap');
    }

    public function test_show_returns_product_with_stats(): void
    {
        $this->actingAsAdmin();
        $product = MerchProduct::factory()->create();
        MerchOrder::factory()->fulfilled()->create([
            'merch_product_id' => $product->id,
            'quantity' => 3,
        ]);

        $this->getJson('/api/admin/products/'.$product->id)
            ->assertOk()
            ->assertJsonPath('data.product.id', $product->id)
            ->assertJsonPath('data.stats.unitsSold', 3)
            ->assertJsonStructure(['data' => ['product', 'stats', 'recentOrders']]);
    }

    public function test_admin_can_create_product(): void
    {
        $this->actingAsAdmin();
        $creator = User::factory()->create();

        $response = $this->postJson('/api/admin/products', [
            'creatorId' => $creator->id,
            'name' => 'Limited Tee',
            'priceAmount' => 500000,
            'discountAmount' => 50000,
            'inventoryCount' => 25,
        ])->assertCreated()->assertJsonPath('data.product.name', 'Limited Tee');

        $this->assertDatabaseHas('merch_products', [
            'id' => $response->json('data.product.id'),
            'creator_id' => $creator->id,
            'inventory_count' => 25,
        ]);
    }

    public function test_create_rejects_discount_greater_than_price(): void
    {
        $this->actingAsAdmin();
        $creator = User::factory()->create();

        $this->postJson('/api/admin/products', [
            'creatorId' => $creator->id,
            'name' => 'Bad Deal',
            'priceAmount' => 1000,
            'discountAmount' => 5000,
        ])->assertStatus(422)->assertJsonValidationErrors('discountAmount');
    }

    public function test_admin_can_update_product(): void
    {
        $this->actingAsAdmin();
        $product = MerchProduct::factory()->create(['name' => 'Before']);

        $this->patchJson('/api/admin/products/'.$product->id, ['name' => 'After'])
            ->assertOk()
            ->assertJsonPath('data.product.name', 'After');

        $this->assertDatabaseHas('merch_products', ['id' => $product->id, 'name' => 'After']);
    }

    public function test_admin_can_delete_product(): void
    {
        $this->actingAsAdmin();
        $product = MerchProduct::factory()->create();

        $this->deleteJson('/api/admin/products/'.$product->id)->assertOk();

        $this->assertDatabaseMissing('merch_products', ['id' => $product->id]);
    }

    public function test_admin_can_publish_and_unpublish_product(): void
    {
        $this->actingAsAdmin();
        $product = MerchProduct::factory()->draft()->create();

        $this->postJson('/api/admin/products/'.$product->id.'/publish', ['action' => 'publish'])
            ->assertOk()
            ->assertJsonPath('data.product.status', 'active');

        $this->postJson('/api/admin/products/'.$product->id.'/publish', ['action' => 'unpublish'])
            ->assertOk()
            ->assertJsonPath('data.product.status', 'archived');
    }

    public function test_admin_can_adjust_inventory_by_delta(): void
    {
        $this->actingAsAdmin();
        $product = MerchProduct::factory()->create(['inventory_count' => 10]);

        $this->postJson('/api/admin/products/'.$product->id.'/inventory', ['delta' => 5])
            ->assertOk()
            ->assertJsonPath('data.product.inventoryCount', 15);

        $this->postJson('/api/admin/products/'.$product->id.'/inventory', ['set' => 3])
            ->assertOk()
            ->assertJsonPath('data.product.inventoryCount', 3);
    }

    public function test_inventory_cannot_go_negative(): void
    {
        $this->actingAsAdmin();
        $product = MerchProduct::factory()->create(['inventory_count' => 2]);

        $this->postJson('/api/admin/products/'.$product->id.'/inventory', ['delta' => -5])
            ->assertStatus(422)
            ->assertJsonValidationErrors('delta');

        $this->assertDatabaseHas('merch_products', ['id' => $product->id, 'inventory_count' => 2]);
    }

    public function test_admin_can_export_products_csv(): void
    {
        $this->actingAsAdmin();
        MerchProduct::factory()->create(['name' => 'Exported Item']);

        $response = $this->get('/api/admin/products/export');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('Exported Item', $response->streamedContent());
    }
}
