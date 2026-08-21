<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Wraps the fully-rendered HTML of an already-built outgoing email so it can
 * be resent, unchanged, to an Email Interceptor destination as its own
 * independent message. Forwarding the final rendered HTML (rather than
 * reconstructing the original Mailable and its view data) guarantees the
 * copy is a byte-for-byte match of what the original recipient saw.
 */
class InterceptedEmailCopy extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $original_subject,
        public string $original_recipient_email,
        public string $html_body,
    ) {
        $this->onQueue('emails');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[Copy] ' . $this->original_subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: $this->html_body,
        );
    }
}
