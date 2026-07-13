<?php

namespace Tests\Feature;

use App\Support\Sms\TwilioSmsSender;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class TwilioSmsSenderTest extends TestCase
{
    public function test_it_posts_form_encoded_payload_with_from_number(): void
    {
        Http::fake([
            'api.twilio.com/*' => Http::response(['sid' => 'SM123'], 201),
        ]);

        $sender = new TwilioSmsSender(
            accountSid: 'AC_test',
            authToken: 'secret',
            from: '+15550001111',
        );

        $sender->send('+2348012345678', 'Your code is 1234');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.twilio.com/2010-04-01/Accounts/AC_test/Messages.json'
                && $request->method() === 'POST'
                && $request['To'] === '+2348012345678'
                && $request['Body'] === 'Your code is 1234'
                && $request['From'] === '+15550001111'
                && ! isset($request['MessagingServiceSid'])
                && $request->hasHeader('Authorization');
        });
    }

    public function test_it_prefers_messaging_service_sid_when_provided(): void
    {
        Http::fake([
            'api.twilio.com/*' => Http::response(['sid' => 'SM124'], 201),
        ]);

        $sender = new TwilioSmsSender(
            accountSid: 'AC_test',
            authToken: 'secret',
            from: '+15550001111',
            messagingServiceSid: 'MG_abcdef',
        );

        $sender->send('+2348012345678', 'Hi');

        Http::assertSent(function ($request) {
            return $request['MessagingServiceSid'] === 'MG_abcdef'
                && ! isset($request['From']);
        });
    }

    public function test_it_throws_when_twilio_returns_error(): void
    {
        Http::fake([
            'api.twilio.com/*' => Http::response(['message' => 'invalid'], 400),
        ]);

        $sender = new TwilioSmsSender(
            accountSid: 'AC_test',
            authToken: 'secret',
            from: '+15550001111',
        );

        $this->expectException(RuntimeException::class);

        $sender->send('+2348012345678', 'boom');
    }
}
