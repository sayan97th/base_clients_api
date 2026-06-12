<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ServiceVerificationEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $recipient_name,
        public string $recipient_email,
        public string $verification_token,
        public string $mailer_name,
        public string $sent_at,
        public array  $service_info = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[Service Check] Email Delivery Verification — ' . config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.service-verification',
            with: [
                'recipient_name'     => $this->recipient_name,
                'recipient_email'    => $this->recipient_email,
                'verification_token' => $this->verification_token,
                'mailer_name'        => $this->mailer_name,
                'sent_at'            => $this->sent_at,
                'service_info'       => $this->service_info,
                'app_name'           => config('app.name'),
                'app_url'            => config('app.frontend_url', config('app.url')),
            ],
        );
    }
}
