<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminNotificationsApiTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_non_admin_cannot_list_admin_notifications(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/admin/notifications')->assertForbidden();
    }

    public function test_admin_can_list_and_filter_notifications(): void
    {
        $this->admin();

        $user = User::factory()->create([
            'name' => 'Aisha Creator',
            'email' => 'aisha@example.com',
        ]);

        $target = UserNotification::create([
            'user_id' => $user->id,
            'type' => 'comment',
            'title' => 'New comment on your post',
            'body' => 'Someone commented on your latest upload.',
            'data' => ['videoId' => 123],
        ]);

        UserNotification::create([
            'user_id' => $user->id,
            'type' => 'message',
            'title' => 'New message',
            'body' => 'You have a new direct message.',
            'data' => ['conversationId' => 99],
            'read_at' => now(),
        ]);

        $this->getJson('/api/v1/admin/notifications?q=comment&status=unread')
            ->assertOk()
            ->assertJsonPath('data.notifications.0.id', $target->id)
            ->assertJsonPath('meta.summary.unread', 1)
            ->assertJsonPath('meta.summary.total', 2);

        $this->getJson('/api/v1/admin/notifications/'.$target->id)
            ->assertNotFound();
    }
}
