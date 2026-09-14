<?php

namespace Tests\Feature;

use App\Models\CoinPurchase;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_notifications_can_be_filtered_and_paginated(): void
    {
        $user = User::factory()->create();
        UserNotification::create(['user_id' => $user->id, 'type' => 'like', 'title' => 'Like', 'body' => 'Liked your post']);
        UserNotification::create(['user_id' => $user->id, 'type' => 'like', 'title' => 'Like', 'body' => 'Liked your post']);
        UserNotification::create(['user_id' => $user->id, 'type' => 'gift', 'title' => 'Gift', 'body' => 'Sent a gift']);
        Sanctum::actingAs($user);

        $this->getJson('/api/notifications?type=like&per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data.notifications')
            ->assertJsonPath('meta.notifications.total', 2);
    }

    public function test_user_can_clear_only_their_notifications_and_activity_includes_coin_purchases(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        UserNotification::create(['user_id' => $user->id, 'type' => 'like', 'title' => 'Like', 'body' => 'Liked your post']);
        UserNotification::create(['user_id' => $other->id, 'type' => 'like', 'title' => 'Like', 'body' => 'Liked your post']);
        CoinPurchase::factory()->create(['user_id' => $user->id, 'coins' => 500, 'status' => 'completed']);
        Sanctum::actingAs($user);

        $activity = $this->getJson('/api/activity')->assertOk()->json('data.activity');
        $this->assertTrue(collect($activity)->contains('type', 'coin_purchase'));
        $this->deleteJson('/api/notifications')->assertOk();
        $this->assertDatabaseCount('user_notifications', 1);
    }
}
