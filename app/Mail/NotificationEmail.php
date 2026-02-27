<?php

namespace App\Mail;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NotificationEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public Notification $notification,
    ) {}

    public function envelope(): Envelope
    {
        $subjects = [
            'payment' => 'Payment Notification',
            'post' => 'New Post Update',
            'system' => 'System Notification',
        ];

        $subject = $subjects[$this->notification->type] ?? 'New Notification';

        return new Envelope(
            subject: "{$subject} - " . config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.notification',
            with: [
                'user_name' => $this->user->full_name,
                'notification_type' => $this->notification->type,
                'notification_message' => $this->notification->message,
                'preview_text' => $this->notification->preview_text,
                'action_url' => $this->buildActionUrl(),
                'app_name' => config('app.name'),
            ],
        );
    }

    protected function buildActionUrl(): ?string
    {
        if (!$this->notification->link) {
            return config('app.frontend_url') . '/notifications';
        }

        $link = $this->notification->link;

        if (!str_starts_with($link, 'http')) {
            return config('app.frontend_url') . $link;
        }

        return $link;
    }
}
