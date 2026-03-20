<?php

namespace App\Mail;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NotificationEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public Notification $notification,
        public array $mail_data = [],
    ) {
        $this->onQueue('emails');
    }

    public function envelope(): Envelope
    {
        $subjects = [
            'payment' => 'Payment Receipt',
            'post'    => 'New Post Update',
            'system'  => 'System Notification',
            'order'   => 'Order Update',
        ];

        $subject = $subjects[$this->notification->type] ?? 'New Notification';

        return new Envelope(
            subject: "{$subject} - " . config('app.name'),
        );
    }

    public function content(): Content
    {
        $is_payment_receipt = $this->notification->type === 'payment'
            && !empty($this->mail_data['line_items']);

        if ($is_payment_receipt) {
            return new Content(
                view: 'emails.payment-receipt',
                with: $this->buildReceiptData(),
            );
        }

        return new Content(
            view: 'emails.notification',
            with: $this->buildNotificationData(),
        );
    }

    protected function buildNotificationData(): array
    {
        return [
            'user_name'             => $this->user->full_name,
            'user_email'            => $this->user->email,
            'notification_type'     => $this->notification->type,
            'notification_message'  => $this->notification->message,
            'preview_text'          => $this->notification->preview_text,
            'notification_date'     => $this->notification->date,
            'notification_relative' => $this->notification->relative_time,
            'notification_id'       => $this->notification->id,
            'action_url'            => $this->buildActionUrl(),
            'preferences_url'       => config('app.frontend_url') . '/settings/notifications',
            'app_name'              => config('app.name'),
        ];
    }

    protected function buildReceiptData(): array
    {
        return [
            'user_name'            => $this->user->full_name,
            'user_email'           => $this->user->email,
            'notification_message' => $this->notification->message,
            'invoice_number'       => $this->mail_data['invoice_number'],
            'invoice_url'          => $this->mail_data['invoice_url'],
            'invoice_pdf_url'      => $this->mail_data['invoice_pdf_url'] ?? null,
            'currency_type'        => $this->mail_data['currency_type'] ?? 'usd',
            'subtotal_amount'      => $this->mail_data['subtotal_amount'] ?? 0,
            'total_amount'         => $this->mail_data['total_amount'] ?? 0,
            'credit_amount'        => $this->mail_data['credit_amount'] ?? 0,
            'line_items'           => $this->mail_data['line_items'],
            'billed_to'            => $this->mail_data['billed_to'] ?? null,
            'coupon_discounts'     => $this->mail_data['coupon_discounts'] ?? [],
            'preferences_url'      => config('app.frontend_url') . '/settings/notifications',
            'app_name'             => config('app.name'),
        ];
    }

    protected function buildActionUrl(): ?string
    {
        if (!$this->notification->link) {
            return config('app.frontend_url') . '/notifications';
        }

        $link = $this->notification->link;

        if (!str_starts_with($link, 'http')) {
            return config('app.frontend_url') . $link;
        }

        return $link;
    }
}
