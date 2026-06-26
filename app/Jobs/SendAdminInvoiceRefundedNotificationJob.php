<?php

namespace App\Jobs;

use App\Mail\AdminInvoiceRefundedNotification;
use App\Models\Invoice;
use App\Services\EmailNotificationSettingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Fans out a refund alert to every admin recipient configured in the Email
 * Notification Settings. Dispatched on every refund / partial refund so the
 * admin team always stays aware of money leaving the platform.
 */
class SendAdminInvoiceRefundedNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $max_exceptions = 2;

    public array $backoff = [30, 60, 120];

    public function __construct(
        public string $invoice_id,
        public float $refund_amount,
        public float $total_refunded,
        public float $credit_refund,
        public float $card_refund,
        public bool $is_full_refund,
        public ?string $stripe_refund_id,
        public string $actor_name,
    ) {
        $this->onQueue('emails');
    }

    public function handle(): void
    {
        $invoice = Invoice::with(['user'])->find($this->invoice_id);

        if (! $invoice) {
            return;
        }

        $recipients   = EmailNotificationSettingService::resolveAdminRecipients();
        $client       = $invoice->user;
        $client_name  = $client?->full_name ?? $client?->email ?? 'Unknown client';
        $client_email = $client?->email ?? '';
        $initials     = $this->buildInitials($client_name);

        $view_invoice_url = rtrim(config('app.admin_url', config('app.frontend_url')), '/') . '/admin/invoices/' . $invoice->id;
        $settings_url     = rtrim(config('app.admin_url', config('app.frontend_url')), '/') . '/admin/email-notifications';
        $refund_date      = $invoice->refunded_at?->format('F j, Y \a\t g:i A') ?? now()->format('F j, Y \a\t g:i A');

        $refund_amount  = '$' . number_format($this->refund_amount, 2);
        $total_amount   = '$' . number_format((float) $invoice->total_amount, 2);
        $total_refunded = '$' . number_format($this->total_refunded, 2);
        $credit_refund  = $this->credit_refund > 0 ? '$' . number_format($this->credit_refund, 2) : null;
        $card_refund    = $this->card_refund > 0 ? '$' . number_format($this->card_refund, 2) : null;

        foreach ($recipients as $position => $recipient) {
            SendEmailJob::dispatchWithThrottle(
                new AdminInvoiceRefundedNotification(
                    recipient_name:    $recipient['name'],
                    recipient_email:   $recipient['email'],
                    invoice_number:    $invoice->invoice_number,
                    invoice_unique_id: $invoice->unique_id,
                    client_name:       $client_name,
                    client_email:      $client_email,
                    client_initials:   $initials,
                    refund_amount:     $refund_amount,
                    total_amount:      $total_amount,
                    total_refunded:    $total_refunded,
                    refund_date:       $refund_date,
                    payment_method:    $invoice->payment_method ?? 'Account Balance',
                    is_full_refund:    $this->is_full_refund,
                    credit_refund:     $credit_refund,
                    card_refund:       $card_refund,
                    stripe_refund_id:  $this->stripe_refund_id,
                    actor_name:        $this->actor_name,
                    view_invoice_url:  $view_invoice_url,
                    settings_url:      $settings_url,
                ),
                $recipient['email'],
                $position,
            );
        }
    }

    public function failed(\Throwable $exception): void
    {
        \Log::error('SendAdminInvoiceRefundedNotificationJob failed', [
            'invoice_id' => $this->invoice_id,
            'error'      => $exception->getMessage(),
        ]);
    }

    private function buildInitials(string $name): string
    {
        $parts = array_filter(explode(' ', trim($name)));

        if (count($parts) >= 2) {
            return strtoupper(mb_substr($parts[0], 0, 1) . mb_substr(end($parts), 0, 1));
        }

        return strtoupper(mb_substr($name, 0, 2));
    }
}
