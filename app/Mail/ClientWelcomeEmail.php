<?php

namespace App\Mail;

use App\Models\User;
use App\Support\FrontendUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ClientWelcomeEmail extends Mailable
{
    use Queueable, SerializesModels;

    public string $platform_name;
    public string $platform_url;

    public function __construct(
        public readonly User $user,
        public readonly string $reset_url,
        public readonly ?string $temporary_password,
    ) {
        $this->platform_name = config('app.name');
        $this->platform_url  = FrontendUrl::to();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Welcome to {$this->platform_name} — Your account is ready",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.client-welcome',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
