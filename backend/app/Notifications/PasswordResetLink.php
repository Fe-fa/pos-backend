<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordResetLink extends Notification
{
    use Queueable;

    public function __construct(private readonly string $resetUrl) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Reset Your SwiftPOS Password')
            ->greeting("Hello {$notifiable->first_name},")
            ->line('We received a request to reset your SwiftPOS password.')
            ->action('Reset Password', $this->resetUrl)
            ->line('This link expires in 60 minutes.')
            ->line('If you did not request a password reset, ignore this email.');
    }
}