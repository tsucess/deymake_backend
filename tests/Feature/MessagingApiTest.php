<?php

namespace Tests\Feature;

use App\Events\ConversationMessageCreated;
use App\Events\UserNotificationChanged;
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
        Event::fake([ConversationMessageCreated::class, UserNotificationChanged::class]);

        $sender = User::factory()->create(['name' => 'Sender']);
        $recipient = User::factory()->create([
            'name' => 'Recipient',
            'preferences' => ['language' => 'fr'],
        ]);
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

        $notifications = $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(3, 'data.notifications');

        $this->assertContains(
            trans('messages.notifications.new_message_title', [], 'fr'),
            array_column($notifications->json('data.notifications'), 'title'),
        );

        Event::assertDispatched(UserNotificationChanged::class, function (UserNotificationChanged $event) use ($recipient, $conversationId): bool {
            return $event->userId === $recipient->id
                && $event->action === 'created'
                && $event->notification?->type === 'message'
                && data_get($event->notification?->data, 'conversationId') === $conversationId;
        });

        $this->getJson('/api/v1/conversations/suggested')
            ->assertOk()
            ->assertJsonCount(1, 'data.users')
            ->assertJsonPath('data.users.0.fullName', $extra->name);
    }

    public function test_locale_headers_localize_conversation_messages_and_statuses(): void
    {
        $sender = User::factory()->create(['name' => 'Sender']);
        $recipient = User::factory()->create([
            'name' => 'Recipient',
            'is_online' => false,
        ]);

        Sanctum::actingAs($sender);

        $conversation = $this->withHeaders(['X-Locale' => 'ha'])
            ->postJson('/api/v1/conversations', [
                'userId' => $recipient->id,
            ])
            ->assertCreated()
            ->assertJsonPath('message', trans('messages.conversations.ready', [], 'ha'))
            ->assertJsonPath('data.conversation.status', trans('messages.conversations.no_messages_yet', [], 'ha'));

        $conversationId = $conversation->json('data.conversation.id');

        $this->withHeaders(['X-Locale' => 'yo'])
            ->getJson('/api/v1/conversations')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.conversations.retrieved', [], 'yo'))
            ->assertJsonPath('data.conversations.0.status', trans('messages.conversations.no_messages_yet', [], 'yo'));

        $this->withHeaders(['X-Locale' => 'ig'])
            ->postJson('/api/v1/conversations/'.$conversationId.'/messages', [
                'body' => 'Ndewo',
            ])
            ->assertCreated()
            ->assertJsonPath('message', trans('messages.conversations.message_created', [], 'ig'));

        $status = $this->withHeaders(['X-Locale' => 'es'])
            ->getJson('/api/v1/conversations')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.conversations.retrieved', [], 'es'))
            ->json('data.conversations.0.status');

        $this->assertStringStartsWith(trans('messages.conversations.sent_prefix', [], 'es').' ', $status);
    }
}