<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MessagingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_users_can_create_read_and_continue_conversations(): void
    {
        $sender = User::factory()->create(['name' => 'Sender']);
        $recipient = User::factory()->create(['name' => 'Recipient']);
        $extra = User::factory()->create(['name' => 'Extra']);

        Sanctum::actingAs($sender);

        $this->getJson('/api/v1/conversations/suggested')
            ->assertOk()
            ->assertJsonCount(2, 'data.users');

        $conversationResponse = $this->postJson('/api/v1/conversations', [
            'userId' => $recipient->id,
            'message' => 'Hello there',
        ]);

        $conversationResponse->assertCreated();
        $conversationId = $conversationResponse->json('data.conversation.id');

        $this->getJson('/api/v1/conversations')
            ->assertOk()
            ->assertJsonCount(1, 'data.conversations');

        $this->getJson('/api/v1/conversations/'.$conversationId.'/messages')
            ->assertOk()
            ->assertJsonCount(1, 'data.messages');

        $this->postJson('/api/v1/conversations/'.$conversationId.'/messages', [
            'body' => 'Second message',
        ])->assertCreated();

        $this->postJson('/api/v1/conversations', [
            'userId' => $recipient->id,
            'message' => 'Third message',
        ])->assertCreated()->assertJsonPath('data.conversation.id', $conversationId);

        Sanctum::actingAs($recipient);

        $this->getJson('/api/v1/conversations')
            ->assertOk()
            ->assertJsonPath('data.conversations.0.unreadCount', 3);

        $this->getJson('/api/v1/conversations/'.$conversationId.'/messages')
            ->assertOk()
            ->assertJsonCount(3, 'data.messages');

        $this->postJson('/api/v1/conversations/'.$conversationId.'/read')->assertOk();

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(3, 'data.notifications');

        $this->getJson('/api/v1/conversations/suggested')
            ->assertOk()
            ->assertJsonCount(1, 'data.users')
            ->assertJsonPath('data.users.0.fullName', $extra->name);
    }
}