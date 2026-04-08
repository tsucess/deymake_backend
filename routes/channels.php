<?php

use App\Models\Conversation;
use App\Models\User;
use App\Models\Video;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('conversations.{conversationId}', function (User $user, int $conversationId): bool {
    return Conversation::query()
        ->whereKey($conversationId)
        ->whereHas('participants', fn ($query) => $query->where('users.id', $user->id))
        ->exists();
});

Broadcast::channel('conversation-presence.{conversationId}', function (User $user, int $conversationId): array|bool {
    $authorized = Conversation::query()
        ->whereKey($conversationId)
        ->whereHas('participants', fn ($query) => $query->where('users.id', $user->id))
        ->exists();

    if (! $authorized) {
        return false;
    }

    return [
        'id' => $user->id,
        'fullName' => $user->name,
        'username' => $user->username,
        'avatarUrl' => $user->avatar_url,
    ];
});

Broadcast::channel('notifications.{targetUserId}', function (User $user, int $targetUserId): bool {
    return $user->id === $targetUserId;
});

Broadcast::channel('live.videos.{videoId}.users.{targetUserId}', function (User $user, int $videoId, int $targetUserId): bool {
    if ($user->id !== $targetUserId) {
        return false;
    }

    return Video::query()
        ->whereKey($videoId)
        ->where(fn ($query) => $query
            ->where('is_live', true)
            ->orWhere('user_id', $user->id))
        ->exists();
});

Broadcast::channel('live.videos.{videoId}', function (User $user, int $videoId): bool {
    return Video::query()
        ->whereKey($videoId)
        ->where(fn ($query) => $query
            ->where('is_live', true)
            ->orWhere('user_id', $user->id))
        ->exists();
});

Broadcast::channel('live.videos.{videoId}.creator', function (User $user, int $videoId): bool {
    return Video::query()
        ->whereKey($videoId)
        ->where('user_id', $user->id)
        ->exists();
});