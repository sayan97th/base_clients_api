<?php

namespace App\Mail;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ClientInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $accept_url;
    public string $inviter_name;
    public string $expiry_date;
    public string $platform_name;
    public string $platform_url;

    public function __construct(public readonly Invitation $invitation)
    {
        $this->accept_url   = rtrim(config('app.frontend_url'), '/') . '/client-invitation/' . $invitation->token;
        $this->inviter_name = $invitation->inviter->first_name . ' ' . $invitation->inviter->last_name;
        $this->expiry_date  = $invitation->expires_at->format('F j, Y');
        $this->platform_name = config('app.name', 'BASE Search Marketing');
        $this->platform_url  = config('app.frontend_url');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "You've been invited to join {$this->platform_name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.client-invitation',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
