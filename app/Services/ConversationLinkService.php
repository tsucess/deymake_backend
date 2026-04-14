<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\User;

class ConversationLinkService
{
    public function findOrCreateDirectConversation(User $firstUser, User $secondUser): Conversation
    {
        $participantIds = [$firstUser->id, $secondUser->id];

        $conversation = Conversation::query()
            ->whereHas('participants', fn ($query) => $query->where('users.id', $participantIds[0]))
            ->whereHas('participants', fn ($query) => $query->where('users.id', $participantIds[1]))
            ->whereDoesntHave('participants', fn ($query) => $query->whereNotIn('users.id', $participantIds))
            ->first();

        if ($conversation) {
            return $conversation;
        }

        $conversation = Conversation::create();
        $conversation->participants()->attach([
            $firstUser->id => ['last_read_at' => now()],
            $secondUser->id => ['last_read_at' => null],
        ]);

        return $conversation;
    }
}