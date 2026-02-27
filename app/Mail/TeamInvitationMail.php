<?php

namespace App\Mail;

use App\Models\TeamInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TeamInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public TeamInvitation $invitation,
        public bool $is_existing_user = false,
    ) {}

    public function envelope(): Envelope
    {
        $team_name = $this->invitation->team->name;
        $inviter_name = $this->invitation->invitedBy->full_name;

        return new Envelope(
            subject: "{$inviter_name} invited you to join {$team_name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.team-invitation',
            with: [
                'accept_url' => $this->generateAcceptUrl(),
                'team_name' => $this->invitation->team->name,
                'organization_name' => $this->invitation->team->organization->name,
                'inviter_name' => $this->invitation->invitedBy->full_name,
                'is_existing_user' => $this->is_existing_user,
                'expires_at' => $this->invitation->expires_at->format('F j, Y'),
            ],
        );
    }

    protected function generateAcceptUrl(): string
    {
        $base_url = config('app.frontend_url');

        if ($this->is_existing_user) {
            return "{$base_url}/invitations/{$this->invitation->token}/accept";
        }

        return "{$base_url}/register?invitation={$this->invitation->token}";
    }
}
