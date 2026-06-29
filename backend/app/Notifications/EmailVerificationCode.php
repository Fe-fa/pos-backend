<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmailVerificationCode extends Notification
{
    use Queueable;

    public function __construct(private readonly string $code) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your SwiftPOS Verification Code')
            ->greeting("Hello {$notifiable->first_name},")
            ->line('Use the code below to verify your email address. It expires in 15 minutes.')
            ->line("**{$this->code}**")
            ->line('If you did not register for SwiftPOS, ignore this email.');
    }
}