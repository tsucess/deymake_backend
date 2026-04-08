<?php

namespace App\Support;

class UserDefaults
{
    public static function preferences(): array
    {
        return [
            'notificationSettings' => [
                'messages' => true,
                'comments' => true,
                'likes' => true,
                'subscriptions' => true,
                'inAppRealtime' => true,
                'browserRealtime' => true,
            ],
            'language' => 'en',
            'displayPreferences' => [
                'theme' => 'system',
                'autoplay' => true,
            ],
            'accessibilityPreferences' => [
                'captions' => false,
                'reducedMotion' => false,
            ],
        ];
    }
}