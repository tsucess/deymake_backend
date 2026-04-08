<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\UserDefaults;
use App\Support\UserNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserNotifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_default_preferences_include_realtime_delivery_toggles(): void
    {
        $defaults = UserDefaults::preferences();

        $this->assertTrue(data_get($defaults, 'notificationSettings.inAppRealtime'));
        $this->assertTrue(data_get($defaults, 'notificationSettings.browserRealtime'));
    }

    public function test_notification_type_preferences_gate_notification_creation(): void
    {
        $actor = User::factory()->create();
        $recipient = User::factory()->create([
            'preferences' => [
                'notificationSettings' => [
                    'messages' => false,
                    'comments' => false,
                    'likes' => false,
                    'subscriptions' => false,
                ],
            ],
        ]);

        UserNotifier::sendMessage($recipient->id, $actor->id, 99, 'Muted message');
        UserNotifier::send($recipient->id, $actor->id, 'comment', 'Comment', 'Muted comment');
        UserNotifier::send($recipient->id, $actor->id, 'video_like', 'Like', 'Muted like');
        UserNotifier::send($recipient->id, $actor->id, 'live', 'Live now', 'Muted live alert');
        UserNotifier::send($recipient->id, $actor->id, 'membership_created', 'Membership', 'This should still be delivered');

        $this->assertDatabaseMissing('user_notifications', ['user_id' => $recipient->id, 'type' => 'message']);
        $this->assertDatabaseMissing('user_notifications', ['user_id' => $recipient->id, 'type' => 'comment']);
        $this->assertDatabaseMissing('user_notifications', ['user_id' => $recipient->id, 'type' => 'video_like']);
        $this->assertDatabaseMissing('user_notifications', ['user_id' => $recipient->id, 'type' => 'live']);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $recipient->id, 'type' => 'membership_created']);
    }
}