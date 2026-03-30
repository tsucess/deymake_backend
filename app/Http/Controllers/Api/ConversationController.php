<?php

namespace App\Http\Controllers\Api;

use App\Events\ConversationMessageCreated;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Http\Resources\ProfileResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Support\UserNotifier;

class ConversationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $conversations = $request->user()->conversations()
            ->with($this->conversationResourceRelations())
            ->latest('conversations.updated_at')
            ->get();

        $this->attachUnreadCounts($conversations, $request->user()->id);

        return response()->json([
            'message' => __('messages.conversations.retrieved'),
            'data' => [
                'conversations' => ConversationResource::collection($conversations),
            ],
        ]);
    }

    public function suggested(Request $request): JsonResponse
    {
        $existingParticipantIds = $request->user()->conversations()
            ->with('participants:id')
            ->get()
            ->flatMap(fn (Conversation $conversation) => $conversation->participants->pluck('id'))
            ->push($request->user()->id)
            ->unique()
            ->values();

        $users = User::query()
            ->withProfileAggregates()
            ->whereNotIn('id', $existingParticipantIds)
            ->orderBy('name')
            ->limit(5)
            ->get();

        return response()->json([
            'message' => __('messages.conversations.suggested_retrieved'),
            'data' => [
                'users' => ProfileResource::collection($users),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'userId' => ['required', 'integer', 'exists:users,id'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);

        abort_if((int) $validated['userId'] === (int) $request->user()->id, 422, __('messages.conversations.self_not_allowed'));

        $participantIds = [(int) $request->user()->id, (int) $validated['userId']];

        $conversation = Conversation::query()
            ->whereHas('participants', fn ($query) => $query->where('users.id', $participantIds[0]))
            ->whereHas('participants', fn ($query) => $query->where('users.id', $participantIds[1]))
            ->whereDoesntHave('participants', fn ($query) => $query->whereNotIn('users.id', $participantIds))
            ->first();

        if (! $conversation) {
            $conversation = Conversation::create();
            $conversation->participants()->attach([
                $request->user()->id => ['last_read_at' => now()],
                $validated['userId'] => ['last_read_at' => null],
            ]);
        }

        if (! empty($validated['message'])) {
            Message::create([
                'conversation_id' => $conversation->id,
                'user_id' => $request->user()->id,
                'body' => $validated['message'],
            ]);

            $conversation->touch();
            UserNotifier::sendMessage($validated['userId'], $request->user()->id, $conversation->id, $validated['message']);
        }

        $this->loadConversationForResource($conversation, $request->user()->id);

        return response()->json([
            'message' => __('messages.conversations.ready'),
            'data' => [
                'conversation' => new ConversationResource($conversation),
            ],
        ], 201);
    }

    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureParticipant($request, $conversation);

        $validated = $request->validate([
            'after' => ['nullable', 'integer', 'min:0'],
        ]);

        $messagesQuery = $conversation->messages()
            ->with(['user' => fn ($query) => $query->withProfileAggregates()])
            ->oldest();

        if (($validated['after'] ?? null) !== null) {
            $messagesQuery->where('messages.id', '>', (int) $validated['after']);
        }

        $messages = $messagesQuery->get();

        return response()->json([
            'message' => __('messages.conversations.messages_retrieved'),
            'data' => [
                'messages' => MessageResource::collection($messages),
            ],
        ]);
    }

    public function storeMessage(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureParticipant($request, $conversation);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'user_id' => $request->user()->id,
            'body' => $validated['body'],
        ]);

        $conversation->touch();

        $conversation->participants()
            ->updateExistingPivot($request->user()->id, ['last_read_at' => now()]);

        $recipientIds = $conversation->participants()
            ->where('users.id', '!=', $request->user()->id)
            ->pluck('users.id');

        foreach ($recipientIds as $recipientId) {
            UserNotifier::sendMessage((int) $recipientId, $request->user()->id, $conversation->id, $validated['body']);
        }

        $message->load(['user' => fn ($query) => $query->withProfileAggregates()]);

        ConversationMessageCreated::dispatch($message);

        return response()->json([
            'message' => __('messages.conversations.message_created'),
            'data' => [
                'message' => new MessageResource($message),
            ],
        ], 201);
    }

    public function markRead(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureParticipant($request, $conversation);

        $conversation->participants()->updateExistingPivot($request->user()->id, ['last_read_at' => now()]);

        return response()->json([
            'message' => __('messages.conversations.marked_read'),
        ]);
    }

    private function ensureParticipant(Request $request, Conversation $conversation): void
    {
        abort_if(! $conversation->participants()->where('users.id', $request->user()->id)->exists(), 403);
    }

    private function conversationResourceRelations(): array
    {
        return [
            'participants' => fn ($query) => $query->withProfileAggregates(),
            'latestMessage.user' => fn ($query) => $query->withProfileAggregates(),
        ];
    }

    private function loadConversationForResource(Conversation $conversation, int $userId): void
    {
        $conversation->load($this->conversationResourceRelations());
        $this->attachUnreadCounts(new EloquentCollection([$conversation]), $userId);
    }

    private function attachUnreadCounts(EloquentCollection $conversations, int $userId): void
    {
        if ($conversations->isEmpty()) {
            return;
        }

        $unreadCounts = Message::query()
            ->selectRaw('messages.conversation_id, COUNT(*) as unread_count')
            ->join('conversation_participants as participant_reads', function ($join) use ($userId): void {
                $join->on('participant_reads.conversation_id', '=', 'messages.conversation_id')
                    ->where('participant_reads.user_id', '=', $userId);
            })
            ->whereIn('messages.conversation_id', $conversations->modelKeys())
            ->where('messages.user_id', '!=', $userId)
            ->where(function ($query): void {
                $query->whereNull('participant_reads.last_read_at')
                    ->orWhereColumn('messages.created_at', '>', 'participant_reads.last_read_at');
            })
            ->groupBy('messages.conversation_id')
            ->get()
            ->pluck('unread_count', 'conversation_id');

        $conversations->each(function (Conversation $conversation) use ($unreadCounts): void {
            $conversation->setAttribute('unread_count', (int) ($unreadCounts[$conversation->id] ?? 0));
        });
    }
}