<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class FeatureAnnouncementEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $title,
        public string $body,
        public ?string $action_url = null,
        public ?string $action_label = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->title . ' - ' . config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.feature-announcement',
            with: [
                'user_name' => $this->user->full_name,
                'title' => $this->title,
                'body' => $this->body,
                'action_url' => $this->action_url,
                'action_label' => $this->action_label ?? 'Learn More',
                'app_name' => config('app.name'),
            ],
        );
    }
}
