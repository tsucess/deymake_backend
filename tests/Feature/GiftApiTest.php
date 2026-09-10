<?php

namespace Tests\Feature;

use App\Events\LiveEngagementCreated;
use App\Models\CoinPurchase;
use App\Models\Gift;
use App\Models\GiftTransaction;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GiftApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_returns_active_gifts_only(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $active = Gift::factory()->create(['name' => 'Active Rose']);
        Gift::factory()->inactive()->create(['name' => 'Hidden Gift']);

        $this->getJson('/api/v1/gifts')
            ->assertOk()
            ->assertJsonPath('data.gifts.0.id', $active->id)
            ->assertJsonMissing(['name' => 'Hidden Gift']);
    }

    public function test_gift_requires_enough_coins_and_does_not_create_transaction(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $gift = Gift::factory()->create(['coin_cost' => 100]);
        Sanctum::actingAs($sender);

        $this->postJson("/api/v1/gifts/{$gift->id}/send", ['recipientId' => $recipient->id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Insufficient coin balance.');

        $this->assertDatabaseCount('gift_transactions', 0);
    }

    public function test_user_can_send_gift_and_live_send_dispatches_event_and_creator_credit(): void
    {
        Event::fake([LiveEngagementCreated::class]);
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $gift = Gift::factory()->create(['coin_cost' => 25, 'price_amount' => 2500]);
        $video = Video::create([
            'user_id' => $recipient->id,
            'type' => 'video',
            'title' => 'Live session',
            'is_live' => true,
            'allow_gifts' => true,
            'is_draft' => false,
        ]);
        CoinPurchase::factory()->create(['user_id' => $sender->id, 'coins' => 100, 'status' => 'completed']);
        Sanctum::actingAs($sender);

        $this->postJson("/api/v1/gifts/{$gift->id}/send", [
            'recipientId' => $recipient->id,
            'videoId' => $video->id,
            'quantity' => 2,
            'message' => 'Great stream!',
        ])->assertCreated()
            ->assertJsonPath('data.transaction.giftName', $gift->name)
            ->assertJsonPath('data.transaction.coinAmount', 50);

        $this->assertDatabaseHas('gift_transactions', [
            'sender_id' => $sender->id,
            'recipient_id' => $recipient->id,
            'video_id' => $video->id,
            'coin_amount' => 50,
            'creator_earnings' => 5000,
            'status' => 'completed',
        ]);
        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $recipient->id,
            'type' => 'gift_credit',
            'amount' => 5000,
        ]);
        Event::assertDispatched(LiveEngagementCreated::class, fn (LiveEngagementCreated $event) => $event->videoId === $video->id);
    }

    public function test_user_cannot_send_gift_to_self_and_can_read_history(): void
    {
        $user = User::factory()->create();
        $gift = Gift::factory()->create(['coin_cost' => 1]);
        GiftTransaction::factory()->create(['sender_id' => $user->id]);
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/gifts/{$gift->id}/send", ['recipientId' => $user->id])
            ->assertStatus(422);
        $this->getJson('/api/v1/gifts/sent')->assertOk()->assertJsonCount(1, 'data.transactions');
    }
}
