<?php

namespace Tests\Feature;

use App\Events\ConversationMessageCreated;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MessagingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_users_can_create_read_and_continue_conversations(): void
    {
        Event::fake([ConversationMessageCreated::class]);

        $sender = User::factory()->create(['name' => 'Sender']);
        $recipient = User::factory()->create(['name' => 'Recipient']);
        $extra = User::factory()->create(['name' => 'Extra']);

        $sender->subscribers()->attach($extra->id);
        $recipient->subscribers()->attach($extra->id);

        Sanctum::actingAs($sender);

        $this->getJson('/api/v1/conversations/suggested')
            ->assertOk()
            ->assertJsonCount(2, 'data.users')
            ->assertJsonPath('data.users.0.fullName', $extra->name)
            ->assertJsonPath('data.users.1.fullName', $recipient->name)
            ->assertJsonPath('data.users.1.subscriberCount', 1);

        $conversationResponse = $this->postJson('/api/v1/conversations', [
            'userId' => $recipient->id,
            'message' => 'Hello there',
        ]);

        $conversationResponse
            ->assertCreated()
            ->assertJsonPath('data.conversation.participant.fullName', $recipient->name)
            ->assertJsonPath('data.conversation.participant.subscriberCount', 1)
            ->assertJsonPath('data.conversation.lastMessage.sender.fullName', $sender->name)
            ->assertJsonPath('data.conversation.lastMessage.sender.subscriberCount', 1);

        $conversationId = $conversationResponse->json('data.conversation.id');

        $this->getJson('/api/v1/conversations')
            ->assertOk()
            ->assertJsonCount(1, 'data.conversations')
            ->assertJsonPath('data.conversations.0.participant.subscriberCount', 1)
            ->assertJsonPath('data.conversations.0.lastMessage.body', 'Hello there')
            ->assertJsonPath('data.conversations.0.lastMessage.sender.subscriberCount', 1);

        $this->getJson('/api/v1/conversations/'.$conversationId.'/messages')
            ->assertOk()
            ->assertJsonCount(1, 'data.messages')
            ->assertJsonPath('data.messages.0.sender.subscriberCount', 1);

        $this->postJson('/api/v1/conversations/'.$conversationId.'/messages', [
            'body' => 'Second message',
        ])->assertCreated();

        Event::assertDispatched(ConversationMessageCreated::class, function (ConversationMessageCreated $event) use ($conversationId, $sender): bool {
            return $event->message->conversation_id === $conversationId
                && $event->message->user_id === $sender->id
                && $event->message->body === 'Second message';
        });

        $this->postJson('/api/v1/conversations', [
            'userId' => $recipient->id,
            'message' => 'Third message',
        ])->assertCreated()->assertJsonPath('data.conversation.id', $conversationId);

        $this->getJson('/api/v1/conversations/'.$conversationId.'/messages?after=1')
            ->assertOk()
            ->assertJsonCount(2, 'data.messages')
            ->assertJsonPath('data.messages.0.body', 'Second message')
            ->assertJsonPath('data.messages.1.body', 'Third message');

        Sanctum::actingAs($recipient);

        $this->getJson('/api/v1/conversations')
            ->assertOk()
            ->assertJsonPath('data.conversations.0.unreadCount', 3)
            ->assertJsonPath('data.conversations.0.participant.fullName', $sender->name)
            ->assertJsonPath('data.conversations.0.participant.subscriberCount', 1)
            ->assertJsonPath('data.conversations.0.lastMessage.body', 'Third message')
            ->assertJsonPath('data.conversations.0.lastMessage.sender.subscriberCount', 1);

        $this->getJson('/api/v1/conversations/'.$conversationId.'/messages')
            ->assertOk()
            ->assertJsonCount(3, 'data.messages')
            ->assertJsonPath('data.messages.0.sender.subscriberCount', 1);

        $this->postJson('/api/v1/conversations/'.$conversationId.'/read')->assertOk();

        $this->getJson('/api/v1/conversations')
            ->assertOk()
            ->assertJsonPath('data.conversations.0.unreadCount', 0);

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(3, 'data.notifications');

        $this->getJson('/api/v1/conversations/suggested')
            ->assertOk()
            ->assertJsonCount(1, 'data.users')
            ->assertJsonPath('data.users.0.fullName', $extra->name);
    }
}