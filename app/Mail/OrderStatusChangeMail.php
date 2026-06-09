<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderStatusChangeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $new_status,
        public string $order_id,
    ) {
        $this->onQueue('emails');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Order Status Update — ' . config('app.name'),
        );
    }

    private function formatStatusLabel(string $status): string
    {
        return match ($status) {
            'new_request'     => 'New Request',
            'pending'         => 'New Request',
            'processing'      => 'Processing',
            'completed'       => 'Completed',
            'cancelled'       => 'Cancelled',
            'payment_pending' => 'Payment Pending',
            default           => ucwords(str_replace('_', ' ', $status)),
        };
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.order-status-change',
            with: [
                'user_name'  => $this->user->full_name,
                'user_email' => $this->user->email,
                'new_status' => $this->formatStatusLabel($this->new_status),
                'order_url'  => config('app.frontend_url') . '/orders/' . $this->order_id,
                'app_name'   => config('app.name'),
            ],
        );
    }
}
