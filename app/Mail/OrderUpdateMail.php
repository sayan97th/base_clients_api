<?php

namespace App\Mail;

use App\Models\User;
use App\Support\FrontendUrl;
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
            subject: 'Order Update: ' . $this->update_title . ' — ' . config('app.name'),
        );
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
            view: 'emails.order-update',
            with: [
                'user_name'       => $this->user->full_name,
                'user_email'      => $this->user->email,
                'update_title'    => $this->update_title,
                'update_message'  => $this->update_message,
                'order_url'       => FrontendUrl::to('/orders/' . $this->order_id),
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
