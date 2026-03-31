<?php

namespace App\Support;

use App\Models\User;
use App\Models\UserWebhook;
use Illuminate\Support\Facades\Http;

class DeveloperWebhookDispatcher
{
    public static function dispatch(User $user, string $event, array $payload): void
    {
        UserWebhook::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->get()
            ->each(function (UserWebhook $webhook) use ($event, $payload): void {
                $events = $webhook->events ?? [];

                if ($events !== [] && ! in_array($event, $events, true)) {
                    return;
                }

                try {
                    $response = Http::timeout(5)
                        ->withHeaders([
                            'X-DeyMake-Event' => $event,
                            'X-DeyMake-Signature' => hash_hmac('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES), $webhook->getRawOriginal('secret')),
                        ])
                        ->post($webhook->target_url, $payload);

                    $webhook->forceFill([
                        'last_triggered_at' => now(),
                        'last_status_code' => $response->status(),
                    ])->save();
                } catch (\Throwable) {
                    $webhook->forceFill([
                        'last_triggered_at' => now(),
                        'last_status_code' => 0,
                    ])->save();
                }
            });
    }
}