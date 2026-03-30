<?php

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('conversations.{conversationId}', function (User $user, int $conversationId): bool {
    return Conversation::query()
        ->whereKey($conversationId)
        ->whereHas('participants', fn ($query) => $query->where('users.id', $user->id))
        ->exists();
});