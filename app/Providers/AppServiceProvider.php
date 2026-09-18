<?php

namespace App\Providers;

use App\Contracts\SmsSender;
use App\Services\Payments\PaymentGatewayContract;
use App\Services\Payments\PaymentGatewayManager;
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

        $this->app->singleton(PaymentGatewayManager::class, fn ($app) => new PaymentGatewayManager($app['config']));

        // Default contract binding resolves the configured default provider so
        // legacy method injection keeps working; provider-aware call sites use
        // PaymentGatewayManager to select Paystack or Flutterwave explicitly.
        $this->app->bind(PaymentGatewayContract::class, fn ($app) => $app->make(PaymentGatewayManager::class)->gateway());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
