<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SendEmailVerificationCode extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $code,
        private readonly int $expiresInMinutes,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('messages.auth.verification_code_subject'))
            ->greeting(__('messages.auth.verification_code_greeting'))
            ->line(__('messages.auth.verification_code_email_line'))
            ->line(__('messages.auth.verification_code_email_code', ['code' => $this->code]))
            ->line(__('messages.auth.verification_code_email_expiry', ['minutes' => $this->expiresInMinutes]));
    }
}