<?php

namespace App\Mail;

use App\Models\Invitation;
use App\Support\FrontendUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StaffInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $accept_url;
    public string $inviter_name;
    public string $role_label;
    public string $expiry_date;

    public function __construct(public readonly Invitation $invitation)
    {
        $this->accept_url   = FrontendUrl::to('/accept-invitation/' . $invitation->token);
        $this->inviter_name = $invitation->inviter->first_name . ' ' . $invitation->inviter->last_name;
        $this->role_label   = ucfirst($invitation->role);
        $this->expiry_date  = $invitation->expires_at->format('F j, Y');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "You've been invited to join as {$this->role_label}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.staff-invitation',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
