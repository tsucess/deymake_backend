<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SendPasswordResetLink extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $token,
        public readonly string $email,
        public readonly int $expiresInMinutes,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = rtrim((string) config('app.frontend_url', 'http://localhost:5173'), '/');
        $resetUrl = $frontendUrl.'/reset-password?token='.urlencode($this->token).'&email='.urlencode($this->email);

        return (new MailMessage)
            ->subject(__('messages.auth.password_reset_email_subject'))
            ->line(__('messages.auth.password_reset_email_line'))
            ->action(__('messages.auth.password_reset_email_action'), $resetUrl)
            ->line(__('messages.auth.password_reset_email_expiry', ['minutes' => $this->expiresInMinutes]))
            ->line(__('messages.auth.password_reset_email_ignore'));
    }
}
