<?php

namespace App\Support;

use App\Models\User;
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

    public static function sendTranslated(
        int $recipientId,
        int $actorId,
        string $type,
        string $titleKey,
        string $bodyKey,
        array $replace = [],
        array $data = [],
    ): void {
        $locale = self::recipientLocale($recipientId);

        self::send(
            $recipientId,
            $actorId,
            $type,
            trans($titleKey, $replace, $locale),
            trans($bodyKey, $replace, $locale),
            $data,
        );
    }

    public static function sendMessage(int $recipientId, int $actorId, int $conversationId, string $body): void
    {
        $locale = self::recipientLocale($recipientId);

        self::send(
            $recipientId,
            $actorId,
            'message',
            trans('messages.notifications.new_message_title', [], $locale),
            mb_strimwidth($body, 0, 120, '...'),
            ['conversationId' => $conversationId],
        );
    }

    private static function recipientLocale(int $recipientId): string
    {
        $recipient = User::query()
            ->select(['id', 'preferences'])
            ->find($recipientId);

        return SupportedLocales::match(data_get($recipient?->preferences, 'language'))
            ?? SupportedLocales::resolve(config('app.locale'));
    }
}