<?php

namespace App\Support;

use App\Events\UserNotificationChanged;
use App\Models\User;
use App\Models\UserNotification;

class UserNotifier
{
    public static function send(int $recipientId, int $actorId, string $type, string $title, string $body, array $data = []): void
    {
        if ($recipientId === $actorId) {
            return;
        }

        $context = self::recipientContext($recipientId);

        if (! self::notificationTypeEnabled($type, $context['preferences'])) {
            return;
        }

        self::deliver($recipientId, $type, $title, $body, $data);
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
        if ($recipientId === $actorId) {
            return;
        }

        $context = self::recipientContext($recipientId);

        if (! self::notificationTypeEnabled($type, $context['preferences'])) {
            return;
        }

        self::deliver(
            $recipientId,
            $type,
            trans($titleKey, $replace, $context['locale']),
            trans($bodyKey, $replace, $context['locale']),
            $data,
        );
    }

    public static function sendMessage(int $recipientId, int $actorId, int $conversationId, string $body): void
    {
        if ($recipientId === $actorId) {
            return;
        }

        $context = self::recipientContext($recipientId);

        if (! self::notificationTypeEnabled('message', $context['preferences'])) {
            return;
        }

        self::deliver(
            $recipientId,
            'message',
            trans('messages.notifications.new_message_title', [], $context['locale']),
            mb_strimwidth($body, 0, 120, '...'),
            ['conversationId' => $conversationId],
        );
    }

    private static function deliver(int $recipientId, string $type, string $title, string $body, array $data = []): void
    {
        $notification = UserNotification::create([
            'user_id' => $recipientId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => $data,
        ]);

        event(new UserNotificationChanged($recipientId, 'created', $notification));
    }

    private static function recipientContext(int $recipientId): array
    {
        $recipient = User::query()
            ->select(['id', 'preferences'])
            ->find($recipientId);

        $preferences = is_array($recipient?->preferences) ? $recipient->preferences : [];

        return [
            'preferences' => $preferences,
            'locale' => SupportedLocales::match(data_get($preferences, 'language'))
                ?? SupportedLocales::resolve(config('app.locale')),
        ];
    }

    private static function notificationTypeEnabled(string $type, array $preferences): bool
    {
        $preferenceKey = self::notificationPreferenceKey($type);

        if (! $preferenceKey) {
            return true;
        }

        return data_get($preferences, "notificationSettings.{$preferenceKey}", true) !== false;
    }

    private static function notificationPreferenceKey(string $type): ?string
    {
        return match ($type) {
            'message' => 'messages',
            'comment', 'reply' => 'comments',
            'video_like', 'video_dislike', 'comment_like', 'comment_dislike' => 'likes',
            'subscription', 'live' => 'subscriptions',
            default => null,
        };
    }
}