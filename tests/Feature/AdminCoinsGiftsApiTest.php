<?php

namespace Tests\Feature;

use App\Models\CoinPackage;
use App\Models\CoinPurchase;
use App\Models\Gift;
use App\Models\GiftTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminCoinsGiftsApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_non_admin_cannot_access_coins_overview(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/admin/coins/overview')->assertForbidden();
    }

    public function test_overview_returns_aggregated_metrics(): void
    {
        $this->actingAsAdmin();
        $buyer = User::factory()->create();

        CoinPurchase::factory()->for($buyer)->create([
            'coins' => 700,
            'amount' => 70000,
            'purchased_at' => now()->subDays(2),
        ]);
        GiftTransaction::factory()->create([
            'coin_amount' => 100,
            'quantity' => 2,
            'sent_at' => now()->subDay(),
        ]);

        $this->getJson('/api/admin/coins/overview')
            ->assertOk()
            ->assertJsonPath('data.summary.coinsPurchased', 700)
            ->assertJsonPath('data.summary.revenue', 70000)
            ->assertJsonPath('data.summary.coinsConsumed', 100)
            ->assertJsonPath('data.summary.giftsSent', 2)
            ->assertJsonStructure(['data' => ['summary', 'deltas', 'range']]);
    }

    public function test_overview_handles_empty_datasets(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/admin/coins/overview')
            ->assertOk()
            ->assertJsonPath('data.summary.coinsPurchased', 0)
            ->assertJsonPath('data.summary.revenue', 0)
            ->assertJsonPath('data.summary.avgRevenuePerUser', 0);
    }

    public function test_timeseries_supports_grouping(): void
    {
        $this->actingAsAdmin();
        CoinPurchase::factory()->create(['coins' => 500, 'purchased_at' => now()->subDays(3)]);

        $response = $this->getJson('/api/admin/coins/timeseries?group=month')
            ->assertOk()
            ->assertJsonPath('data.group', 'month')
            ->assertJsonStructure(['data' => ['labels', 'series' => [['key', 'data']], 'range']]);

        $this->assertNotEmpty($response->json('data.labels'));
    }

    public function test_top_senders_are_ranked(): void
    {
        $this->actingAsAdmin();
        $whale = User::factory()->create();
        $minnow = User::factory()->create();

        GiftTransaction::factory()->create(['sender_id' => $whale->id, 'coin_amount' => 900, 'quantity' => 5, 'sent_at' => now()]);
        GiftTransaction::factory()->create(['sender_id' => $minnow->id, 'coin_amount' => 100, 'quantity' => 1, 'sent_at' => now()]);

        $this->getJson('/api/admin/coins/top-senders')
            ->assertOk()
            ->assertJsonPath('data.senders.0.coinsSent', 900)
            ->assertJsonPath('data.senders.0.giftsSent', 5);
    }

    public function test_packages_list_includes_sales_and_revenue(): void
    {
        $this->actingAsAdmin();
        $package = CoinPackage::factory()->create();
        CoinPurchase::factory()->count(2)->create(['coin_package_id' => $package->id, 'amount' => 5000, 'coins' => 100]);

        $this->getJson('/api/admin/coins/packages')
            ->assertOk()
            ->assertJsonPath('data.packages.0.sales', 2)
            ->assertJsonPath('data.packages.0.revenue', 10000)
            ->assertJsonPath('data.packages.0.coinsSold', 200);
    }

    public function test_admin_can_create_update_delete_and_toggle_a_package(): void
    {
        $admin = $this->actingAsAdmin();

        $created = $this->postJson('/api/admin/coins/packages', [
            'name' => 'Mega Pack',
            'coins' => 1400,
            'bonusCoins' => 100,
            'priceAmount' => 200000,
        ])->assertCreated()->assertJsonPath('data.package.name', 'Mega Pack')->json('data.package.id');

        $this->assertDatabaseHas('coin_packages', ['name' => 'Mega Pack', 'coins' => 1400]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.coin_package_created', 'user_id' => $admin->id]);

        $this->patchJson("/api/admin/coins/packages/{$created}", ['priceAmount' => 250000])
            ->assertOk()
            ->assertJsonPath('data.package.priceAmount', 250000);

        $this->postJson("/api/admin/coins/packages/{$created}/toggle")
            ->assertOk()
            ->assertJsonPath('data.package.isActive', false);

        $this->deleteJson("/api/admin/coins/packages/{$created}")->assertOk();
        $this->assertDatabaseMissing('coin_packages', ['id' => $created]);
    }

    public function test_purchases_can_be_filtered_by_flag(): void
    {
        $this->actingAsAdmin();
        CoinPurchase::factory()->create();
        CoinPurchase::factory()->flagged()->create();

        $this->getJson('/api/admin/coins/purchases?flagged=true')
            ->assertOk()
            ->assertJsonCount(1, 'data.purchases')
            ->assertJsonPath('meta.summary.flagged', 1);
    }

    public function test_admin_can_refund_a_coin_purchase(): void
    {
        $admin = $this->actingAsAdmin();
        $purchase = CoinPurchase::factory()->create(['amount' => 50000]);

        $this->postJson("/api/admin/coins/purchases/{$purchase->id}/refund", ['reason' => 'Chargeback'])
            ->assertOk()
            ->assertJsonPath('data.purchase.status', 'refunded');

        $this->assertDatabaseHas('coin_purchases', ['id' => $purchase->id, 'status' => 'refunded']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.coin_purchase_refunded', 'user_id' => $admin->id]);
    }

    public function test_admin_can_flag_a_coin_purchase(): void
    {
        $this->actingAsAdmin();
        $purchase = CoinPurchase::factory()->create();

        $this->postJson("/api/admin/coins/purchases/{$purchase->id}/review", [
            'action' => 'flag',
            'reason' => 'Velocity',
        ])->assertOk()->assertJsonPath('data.purchase.isFlagged', true);

        $this->assertDatabaseHas('coin_purchases', ['id' => $purchase->id, 'is_flagged' => true]);
    }

    public function test_transactions_feed_merges_purchases_and_gifts(): void
    {
        $this->actingAsAdmin();
        CoinPurchase::factory()->create(['purchased_at' => now()->subMinutes(5)]);
        GiftTransaction::factory()->create(['sent_at' => now()->subMinutes(2)]);

        $response = $this->getJson('/api/admin/coins/transactions')
            ->assertOk()
            ->assertJsonCount(2, 'data.transactions');

        $kinds = collect($response->json('data.transactions'))->pluck('kind')->all();
        $this->assertContains('purchase', $kinds);
        $this->assertContains('gift', $kinds);
    }

    public function test_coin_transactions_can_be_exported(): void
    {
        $this->actingAsAdmin();
        CoinPurchase::factory()->create();

        $this->get('/api/admin/coins/export')->assertOk();
    }

    public function test_non_admin_cannot_list_gifts(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/admin/gifts')->assertForbidden();
    }

    public function test_gifts_list_returns_catalog_with_summary(): void
    {
        $this->actingAsAdmin();
        Gift::factory()->count(2)->create();
        Gift::factory()->inactive()->create();

        $this->getJson('/api/admin/gifts')
            ->assertOk()
            ->assertJsonPath('meta.summary.totalGifts', 3)
            ->assertJsonPath('meta.summary.activeGifts', 2)
            ->assertJsonPath('meta.summary.inactiveGifts', 1);
    }

    public function test_admin_can_create_update_delete_and_toggle_a_gift(): void
    {
        $admin = $this->actingAsAdmin();

        $id = $this->postJson('/api/admin/gifts', [
            'name' => 'Golden Crown',
            'coinCost' => 250,
            'priceAmount' => 25000,
        ])->assertCreated()->assertJsonPath('data.gift.name', 'Golden Crown')->json('data.gift.id');

        $this->assertDatabaseHas('gifts', ['name' => 'Golden Crown', 'coin_cost' => 250]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.gift_created', 'user_id' => $admin->id]);

        $this->patchJson("/api/admin/gifts/{$id}", ['coinCost' => 300])
            ->assertOk()
            ->assertJsonPath('data.gift.coinCost', 300);

        $this->postJson("/api/admin/gifts/{$id}/toggle")
            ->assertOk()
            ->assertJsonPath('data.gift.isActive', false);

        $this->deleteJson("/api/admin/gifts/{$id}")->assertOk();
        $this->assertDatabaseMissing('gifts', ['id' => $id]);
    }

    public function test_gift_transactions_can_be_filtered_by_status(): void
    {
        $this->actingAsAdmin();
        GiftTransaction::factory()->create();
        GiftTransaction::factory()->refunded()->create();

        $this->getJson('/api/admin/gift-transactions?status=refunded')
            ->assertOk()
            ->assertJsonCount(1, 'data.transactions')
            ->assertJsonPath('meta.summary.refunded', 1);
    }

    public function test_admin_can_refund_a_gift_transaction_and_reverse_earnings(): void
    {
        $admin = $this->actingAsAdmin();
        $recipient = User::factory()->create();
        $transaction = GiftTransaction::factory()->create([
            'recipient_id' => $recipient->id,
            'creator_earnings' => 5000,
        ]);

        $this->postJson("/api/admin/gift-transactions/{$transaction->id}/refund", ['reason' => 'Fraud'])
            ->assertOk()
            ->assertJsonPath('data.transaction.status', 'refunded');

        $this->assertDatabaseHas('gift_transactions', ['id' => $transaction->id, 'status' => 'refunded']);
        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $recipient->id,
            'type' => 'gift_refund',
            'direction' => 'debit',
            'amount' => 5000,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.gift_transaction_refunded', 'user_id' => $admin->id]);
    }

    public function test_admin_can_flag_a_gift_transaction(): void
    {
        $this->actingAsAdmin();
        $transaction = GiftTransaction::factory()->create();

        $this->postJson("/api/admin/gift-transactions/{$transaction->id}/review", ['action' => 'flag'])
            ->assertOk()
            ->assertJsonPath('data.transaction.isFlagged', true);
    }

    public function test_creator_gift_earnings_are_aggregated(): void
    {
        $this->actingAsAdmin();
        $creator = User::factory()->create();
        GiftTransaction::factory()->count(2)->create([
            'recipient_id' => $creator->id,
            'creator_earnings' => 4000,
            'quantity' => 3,
        ]);

        $this->getJson('/api/admin/gift-creator-earnings')
            ->assertOk()
            ->assertJsonPath('data.creators.0.earnings', 8000)
            ->assertJsonPath('data.creators.0.giftsReceived', 6)
            ->assertJsonPath('meta.summary.totalEarnings', 8000);
    }
}
