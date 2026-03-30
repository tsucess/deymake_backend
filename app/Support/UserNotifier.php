<?php

namespace App\Support;

use App\Models\UserNotification;

class UserNotifier
{
    public static function send(int $recipientId, int $actorId, string $type, string $title, string $body, array $data = []): void
    {
        if ($recipientId === $actorId) {
            return;
        }

        UserNotification::create([
            'user_id' => $recipientId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => $data,
        ]);
    }

    public static function sendMessage(int $recipientId, int $actorId, int $conversationId, string $body): void
    {
        self::send(
            $recipientId,
            $actorId,
            'message',
            'New message',
            mb_strimwidth($body, 0, 120, '...'),
            ['conversationId' => $conversationId],
        );
    }
}