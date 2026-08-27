<?php

namespace App\Mail;

use App\Models\User;
use App\Support\FrontendUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ClientPlatformWelcomeEmail extends Mailable
{
    use Queueable, SerializesModels;

    public string $platform_name = 'BASE Portal';
    public string $platform_url;
    public string $support_email = 'abbyallan@basesearchmarketing.com';

    public function __construct(
        public readonly User $user,
        public readonly string $reset_url,
    ) {
        $this->platform_url = FrontendUrl::to();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "You're invited to the new {$this->platform_name} — Set up your account",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.client-platform-welcome',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
