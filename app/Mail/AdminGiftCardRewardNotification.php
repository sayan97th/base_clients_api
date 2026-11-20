<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to admin recipients when a client submits where their promo gift
 * card should be sent (email + preferred brand).
 */
class AdminGiftCardRewardNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $recipient_name,
        public string $recipient_email,
        public string $client_name,
        public string $client_email,
        public string $client_company,
        public string $client_initials,
        public string $promo_code,
        public string $promotion_name,
        public string $gift_card_brand,
        public string $gift_card_label,
        public string $gift_card_email,
        public string $reward_amount,
        public string $reward_percentage,
        public string $order_total,
        public string $order_reference,
        public string $products,
        public bool   $is_payment_pending,
        public string $submitted_at,
        public string $view_order_url,
        public string $view_claims_url,
        public string $settings_url,
        public string $promotion_ends_at = '',
    ) {
        $this->onQueue('emails');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "\u{1F381} Send a {$this->reward_amount} {$this->gift_card_label} gift card to {$this->client_name} | " . config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.admin-gift-card-reward-notification',
        );
    }
}
