<?php

namespace Tests\Feature;

use App\Contracts\SmsSender;
use App\Support\Sms\LogSmsSender;
use App\Support\Sms\TwilioSmsSender;
use Tests\TestCase;

class SmsSenderBindingTest extends TestCase
{
    public function test_it_falls_back_to_log_sender_without_credentials(): void
    {
        config()->set('services.twilio', [
            'account_sid' => null,
            'auth_token' => null,
            'from' => null,
            'messaging_service_sid' => null,
        ]);

        $this->app->forgetInstance(SmsSender::class);

        $this->assertInstanceOf(LogSmsSender::class, $this->app->make(SmsSender::class));
    }

    public function test_it_binds_twilio_when_from_number_present(): void
    {
        config()->set('services.twilio', [
            'account_sid' => 'AC_test',
            'auth_token' => 'secret',
            'from' => '+15550001111',
            'messaging_service_sid' => null,
        ]);

        $this->app->forgetInstance(SmsSender::class);

        $this->assertInstanceOf(TwilioSmsSender::class, $this->app->make(SmsSender::class));
    }

    public function test_it_binds_twilio_when_messaging_service_sid_present(): void
    {
        config()->set('services.twilio', [
            'account_sid' => 'AC_test',
            'auth_token' => 'secret',
            'from' => null,
            'messaging_service_sid' => 'MG_abcdef',
        ]);

        $this->app->forgetInstance(SmsSender::class);

        $this->assertInstanceOf(TwilioSmsSender::class, $this->app->make(SmsSender::class));
    }

    public function test_it_falls_back_when_credentials_present_but_no_sender_source(): void
    {
        config()->set('services.twilio', [
            'account_sid' => 'AC_test',
            'auth_token' => 'secret',
            'from' => null,
            'messaging_service_sid' => null,
        ]);

        $this->app->forgetInstance(SmsSender::class);

        $this->assertInstanceOf(LogSmsSender::class, $this->app->make(SmsSender::class));
    }
}
