<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderUpdateMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $update_title,
        public string $update_message,
        public string $order_id,
    ) {
        $this->onQueue('emails');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Order Update: ' . $this->update_title . ' — ' . config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.order-update',
            with: [
                'user_name'      => $this->user->full_name,
                'user_email'     => $this->user->email,
                'update_title'   => $this->update_title,
                'update_message' => $this->update_message,
                'order_url'      => config('app.frontend_url') . '/orders/' . $this->order_id,
                'app_name'       => config('app.name'),
            ],
        );
    }
}
