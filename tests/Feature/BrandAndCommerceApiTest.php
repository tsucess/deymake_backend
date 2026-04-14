<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\CreatorPlan;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BrandAndCommerceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_brand_campaigns_can_match_talent_and_send_sponsorship_proposals(): void
    {
        $category = Category::create(['name' => 'Dance', 'slug' => 'dance']);
        $brand = User::factory()->create(['name' => 'Brand Owner', 'username' => 'brand.owner']);
        $creator = User::factory()->create([
            'name' => 'Matched Creator',
            'username' => 'matched.creator',
            'creator_verification_status' => 'approved',
            'creator_verified_at' => now(),
        ]);

        CreatorPlan::query()->create([
            'creator_id' => $creator->id,
            'name' => 'Support Club',
            'price_amount' => 1500,
            'currency' => 'NGN',
            'billing_period' => 'monthly',
            'is_active' => true,
        ]);

        Video::query()->create([
            'user_id' => $creator->id,
            'category_id' => $category->id,
            'type' => 'video',
            'title' => 'Viral Dance Clip',
            'is_draft' => false,
            'views_count' => 1200,
        ]);

        Sanctum::actingAs($brand);

        $campaignId = $this->postJson('/api/v1/brand/campaigns', [
            'title' => 'Campus Dance Push',
            'objective' => 'awareness',
            'status' => 'active',
            'budgetAmount' => 50000,
            'currency' => 'NGN',
            'minSubscribers' => 0,
            'targetCategories' => [$category->id],
            'deliverables' => ['1 short video', '1 story repost'],
        ])
            ->assertCreated()
            ->assertJsonPath('message', trans('messages.brand_campaigns.created'))
            ->assertJsonPath('data.campaign.status', 'active')
            ->json('data.campaign.id');

        $this->getJson('/api/v1/brand/campaigns/'.$campaignId.'/matches?verifiedOnly=1&hasActivePlans=1')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.brand_campaigns.matches_retrieved'))
            ->assertJsonPath('data.creators.0.profile.id', $creator->id);

        Sanctum::actingAs($brand);

        $proposalId = $this->postJson('/api/v1/sponsorships/proposals', [
            'recipientId' => $creator->id,
            'brandCampaignId' => $campaignId,
            'title' => 'Dance product placement',
            'brief' => 'Showcase the new product in your next dance video.',
            'feeAmount' => 35000,
            'currency' => 'NGN',
            'deliverables' => ['1 sponsored short', 'Usage rights for 30 days'],
        ])
            ->assertCreated()
            ->assertJsonPath('message', trans('messages.sponsorships.created'))
            ->assertJsonPath('data.proposal.recipient.id', $creator->id)
            ->json('data.proposal.id');

        Sanctum::actingAs($creator);

        $this->getJson('/api/v1/sponsorships/proposals?scope=inbox')
            ->assertOk()
            ->assertJsonPath('data.proposals.0.id', $proposalId)
            ->assertJsonPath('data.proposals.0.status', 'pending');

        $this->patchJson('/api/v1/sponsorships/proposals/'.$proposalId, ['action' => 'accept'])
            ->assertOk()
            ->assertJsonPath('message', trans('messages.sponsorships.updated'))
            ->assertJsonPath('data.proposal.status', 'accepted');
    }

    public function test_merch_products_and_orders_can_flow_from_creator_to_buyer(): void
    {
        $creator = User::factory()->create(['name' => 'Merch Creator', 'username' => 'merch.creator']);
        $buyer = User::factory()->create(['name' => 'Buyer One', 'username' => 'buyer.one']);

        Sanctum::actingAs($creator);

        $productId = $this->postJson('/api/v1/merch/products', [
            'name' => 'DeyMake Hoodie',
            'description' => 'Premium creator hoodie.',
            'priceAmount' => 8000,
            'currency' => 'NGN',
            'inventoryCount' => 10,
            'images' => ['https://cdn.example.com/hoodie.jpg'],
        ])
            ->assertCreated()
            ->assertJsonPath('message', trans('messages.merch.product_created'))
            ->assertJsonPath('data.product.name', 'DeyMake Hoodie')
            ->json('data.product.id');

        $this->getJson('/api/v1/users/'.$creator->id.'/merch')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.merch.products_retrieved'))
            ->assertJsonPath('data.products.0.id', $productId);

        Sanctum::actingAs($buyer);

        $orderId = $this->postJson('/api/v1/merch/products/'.$productId.'/orders', [
            'quantity' => 2,
            'shippingAddress' => ['city' => 'Lagos', 'line1' => '12 Campus Road'],
        ])
            ->assertCreated()
            ->assertJsonPath('message', trans('messages.merch.order_created'))
            ->assertJsonPath('data.order.totalAmount', 16000)
            ->json('data.order.id');

        $this->getJson('/api/v1/merch/orders/mine')
            ->assertOk()
            ->assertJsonPath('data.orders.0.id', $orderId)
            ->assertJsonPath('data.orders.0.status', 'paid');

        Sanctum::actingAs($creator);

        $this->getJson('/api/v1/merch/orders/received')
            ->assertOk()
            ->assertJsonPath('data.orders.0.id', $orderId)
            ->assertJsonPath('data.orders.0.buyer.id', $buyer->id);

        $this->patchJson('/api/v1/merch/orders/'.$orderId, ['action' => 'fulfill'])
            ->assertOk()
            ->assertJsonPath('message', trans('messages.merch.order_updated'))
            ->assertJsonPath('data.order.status', 'fulfilled');

        $this->getJson('/api/v1/monetization/summary')
            ->assertOk()
            ->assertJsonPath('data.summary.earnings.grossRevenue', 16000)
            ->assertJsonPath('data.summary.earnings.availableBalance', 16000);

        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $creator->id,
            'type' => 'merch_sale_credit',
            'amount' => 16000,
        ]);
    }
}