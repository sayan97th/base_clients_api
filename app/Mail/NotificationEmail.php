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
    ) {
        $this->onQueue('emails');
    }

    public function envelope(): Envelope
    {
        $subjects = [
            'payment' => 'Payment Notification',
            'post'    => 'New Post Update',
            'system'  => 'System Notification',
            'order'   => 'Order Update',
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
                'user_name'              => $this->user->full_name,
                'user_email'             => $this->user->email,
                'notification_type'      => $this->notification->type,
                'notification_message'   => $this->notification->message,
                'preview_text'           => $this->notification->preview_text,
                'notification_date'      => $this->notification->date,
                'notification_relative'  => $this->notification->relative_time,
                'notification_id'        => $this->notification->id,
                'action_url'             => $this->buildActionUrl(),
                'preferences_url'        => config('app.frontend_url') . '/settings/notifications',
                'app_name'               => config('app.name'),
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
