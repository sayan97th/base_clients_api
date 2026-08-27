<?php

namespace App\Mail;

use App\Models\Notification;
use App\Models\User;
use App\Support\NotificationLinkValidator;
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
            'invoice' => 'Invoice Update',
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
            'preferences_url'       => $this->buildPreferencesUrl(),
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
            'preferences_url'      => $this->buildPreferencesUrl(),
            'app_name'             => config('app.name'),
        ];
    }

    /**
     * Notification preferences live on the profile page, not a dedicated
     * settings route, and admins and clients are on separate portal domains.
     */
    protected function buildPreferencesUrl(): string
    {
        if ($this->user->hasRole(['super_admin', 'admin', 'staff'])) {
            return rtrim(config('app.admin_url', config('app.frontend_url')), '/') . '/admin/profile';
        }

        return config('app.frontend_url') . '/profile';
    }

    /**
     * Admins and clients are on separate portal domains (see buildPreferencesUrl()).
     * A relative link starting with "/admin" belongs to the admin portal, so it must
     * resolve against admin_url rather than frontend_url or the button would send an
     * admin recipient to the client portal's domain instead of their own.
     *
     * `notification->link` is free text written by many call sites, so it is run
     * through NotificationLinkValidator before it is ever turned into a URL, an
     * unvalidated value here would let a corrupted/crafted link turn this button
     * into an open redirect. Client-portal URLs are also tagged with
     * `notification_id` so that, if an admin/staff account opens this same link
     * while signed in on the admin side, the frontend can route them through the
     * impersonation gate (see NotificationRedirectController) instead of silently
     * bouncing them away from a route they are not allowed on.
     */
    protected function buildActionUrl(): ?string
    {
        $raw_link = $this->notification->link ?: '/notifications';

        if (str_starts_with($raw_link, 'http')) {
            return $this->resolveAbsoluteActionUrl($raw_link);
        }

        $safe_path = NotificationLinkValidator::sanitizeRelativePath($raw_link) ?? '/notifications';
        $is_admin_link = str_starts_with($safe_path, '/admin');
        $base_url = $is_admin_link
            ? config('app.admin_url', config('app.frontend_url'))
            : config('app.frontend_url');

        $url = rtrim($base_url, '/') . $safe_path;

        return $is_admin_link ? $url : $this->tagWithNotificationId($url);
    }

    /**
     * A stored absolute link must resolve to one of our own portal domains, an
     * external host is rejected and swapped for a safe default instead of being
     * used as-is.
     */
    protected function resolveAbsoluteActionUrl(string $raw_link): string
    {
        $admin_origin    = NotificationLinkValidator::parseOrigin(config('app.admin_url', config('app.frontend_url')));
        $frontend_origin = NotificationLinkValidator::parseOrigin(config('app.frontend_url'));
        $allowed_origins = array_values(array_unique(array_filter([$frontend_origin, $admin_origin])));

        if (NotificationLinkValidator::isAllowedAbsoluteUrl($raw_link, $allowed_origins)) {
            $origin = NotificationLinkValidator::parseOrigin($raw_link);

            return $origin === $admin_origin ? $raw_link : $this->tagWithNotificationId($raw_link);
        }

        return $this->tagWithNotificationId(rtrim(config('app.frontend_url'), '/') . '/notifications');
    }

    protected function tagWithNotificationId(string $url): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . 'notification_id=' . $this->notification->id;
    }
}
