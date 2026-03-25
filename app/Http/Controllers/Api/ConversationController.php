<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Http\Resources\ProfileResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $conversations = $request->user()->conversations()
            ->with(['participants', 'messages.user'])
            ->latest('conversations.updated_at')
            ->get();

        return response()->json([
            'message' => 'Conversations retrieved successfully.',
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
            ->whereNotIn('id', $existingParticipantIds)
            ->orderBy('name')
            ->limit(5)
            ->get();

        return response()->json([
            'message' => 'Suggested conversation users retrieved successfully.',
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

        abort_if((int) $validated['userId'] === (int) $request->user()->id, 422, 'You cannot start a conversation with yourself.');

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
            $this->notify($validated['userId'], $request->user()->id, $conversation->id, $validated['message']);
        }

        $conversation->load(['participants', 'messages.user']);

        return response()->json([
            'message' => 'Conversation ready successfully.',
            'data' => [
                'conversation' => new ConversationResource($conversation),
            ],
        ], 201);
    }

    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureParticipant($request, $conversation);

        $messages = $conversation->messages()->with('user')->oldest()->get();

        return response()->json([
            'message' => 'Messages retrieved successfully.',
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
            $this->notify((int) $recipientId, $request->user()->id, $conversation->id, $validated['body']);
        }

        $message->load('user');

        return response()->json([
            'message' => 'Message created successfully.',
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
            'message' => 'Conversation marked as read successfully.',
        ]);
    }

    private function ensureParticipant(Request $request, Conversation $conversation): void
    {
        abort_if(! $conversation->participants()->where('users.id', $request->user()->id)->exists(), 403);
    }

    private function notify(int $recipientId, int $actorId, int $conversationId, string $body): void
    {
        if ($recipientId === $actorId) {
            return;
        }

        UserNotification::create([
            'user_id' => $recipientId,
            'type' => 'message',
            'title' => 'New message',
            'body' => mb_strimwidth($body, 0, 120, '...'),
            'data' => ['conversationId' => $conversationId],
        ]);
    }
}