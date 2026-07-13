<?php

namespace App\Support\Sms;

use App\Contracts\SmsSender;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class TwilioSmsSender implements SmsSender
{
    public function __construct(
        protected string $accountSid,
        protected string $authToken,
        protected string $from,
        protected ?string $messagingServiceSid = null,
    ) {
    }

    public function send(string $phone, string $message): void
    {
        $payload = [
            'To' => $phone,
            'Body' => $message,
        ];

        if ($this->messagingServiceSid) {
            $payload['MessagingServiceSid'] = $this->messagingServiceSid;
        } else {
            $payload['From'] = $this->from;
        }

        $response = Http::asForm()
            ->withBasicAuth($this->accountSid, $this->authToken)
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$this->accountSid}/Messages.json", $payload);

        if ($response->failed()) {
            Log::warning('Twilio SMS dispatch failed', [
                'phone' => $phone,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            throw new RuntimeException('Failed to dispatch SMS via Twilio.');
        }
    }
}
