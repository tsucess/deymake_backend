<?php

namespace App\Support\Sms;

use App\Contracts\SmsSender;
use Illuminate\Support\Facades\Log;

class LogSmsSender implements SmsSender
{
    public function send(string $phone, string $message): void
    {
        Log::channel(config('logging.default'))->info('SMS dispatched', [
            'phone' => $phone,
            'message' => $message,
        ]);
    }
}
