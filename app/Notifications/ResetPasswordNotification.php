<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class ResetPasswordNotification extends Notification
{
    public string $token;

    public function __construct(string $token)
    {
        $this->token = $token;
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $reset_url = $this->buildResetUrl($notifiable);
        $user_name  = $notifiable->first_name ?? 'User';

        return (new MailMessage())
            ->subject('Reset Your Password')
            ->view('emails.reset-password', [
                'reset_url'  => $reset_url,
                'user_name'  => $user_name,
                'expires_in' => config('auth.passwords.users.expire', 60),
            ]);
    }

    protected function buildResetUrl(object $notifiable): string
    {
        $frontend_url = rtrim(config('app.frontend_url'), '/');

        return "{$frontend_url}/reset-password/{$this->token}?email=" . urlencode($notifiable->email);
    }
}
