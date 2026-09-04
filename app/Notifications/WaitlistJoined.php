<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WaitlistJoined extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $name,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('messages.waitlist.mail_subject'))
            ->greeting(__('messages.waitlist.mail_greeting', ['name' => $this->name]))
            ->line(__('messages.waitlist.mail_line1'))
            ->line(__('messages.waitlist.mail_line2'))
            ->salutation(__('messages.waitlist.mail_signoff'));
    }
}
