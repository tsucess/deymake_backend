<?php

namespace App\Providers;

use App\Contracts\SmsSender;
use App\Support\Sms\LogSmsSender;
use App\Support\Sms\TwilioSmsSender;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SmsSender::class, function ($app) {
            $twilio = $app['config']->get('services.twilio', []);

            if (! empty($twilio['account_sid']) && ! empty($twilio['auth_token'])
                && (! empty($twilio['from']) || ! empty($twilio['messaging_service_sid']))) {
                return new TwilioSmsSender(
                    $twilio['account_sid'],
                    $twilio['auth_token'],
                    $twilio['from'] ?? '',
                    $twilio['messaging_service_sid'] ?? null,
                );
            }

            return new LogSmsSender();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
