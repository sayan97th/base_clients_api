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
        public string $status,
        public string $order_id,
        public ?string $order_title = null,
        public ?\DateTimeInterface $purchased_at = null,
        public ?int $link_count = null,
        public ?string $dr_tier_summary = null,
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

    /**
     * Mirrors the display convention already used on the client portal's order pages
     * (order.id.slice(0, 8).toUpperCase(), prefixed with "#") so the reference shown
     * in the email matches what the client sees when they open the order. Dashboard-
     * assigned placements already carry a human "BL-…" id — shown as-is.
     */
    private function formatOrderReference(string $order_id): string
    {
        if (str_starts_with($order_id, 'BL-')) {
            return $order_id;
        }

        return '#' . strtoupper(substr($order_id, 0, 8));
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.order-status-change',
            with: [
                'user_name'       => $this->user->full_name,
                'user_email'      => $this->user->email,
                'new_status'      => $this->formatStatusLabel($this->status),
                'order_url'       => config('app.frontend_url') . '/orders/' . $this->order_id,
                'order_reference' => $this->formatOrderReference($this->order_id),
                'order_title'     => $this->order_title,
                'purchase_date'   => $this->purchased_at?->format('F j, Y'),
                'link_count'      => $this->link_count,
                'dr_tier_summary' => $this->dr_tier_summary,
                'app_name'        => config('app.name'),
            ],
        );
    }
}
