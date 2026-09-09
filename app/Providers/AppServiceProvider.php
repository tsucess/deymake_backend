<?php

namespace App\Providers;

use App\Contracts\SmsSender;
use App\Services\Payments\PaymentGatewayContract;
use App\Services\Payments\PaystackGateway;
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

            return new LogSmsSender;
        });

        $this->app->bind(PaymentGatewayContract::class, function ($app) {
            $paystack = $app['config']->get('services.paystack', []);

            return new PaystackGateway(
                (string) ($paystack['secret_key'] ?? ''),
                (string) ($paystack['base_url'] ?? 'https://api.paystack.co'),
            );
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
