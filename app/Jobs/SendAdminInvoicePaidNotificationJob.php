<?php

namespace App\Jobs;

use App\Mail\AdminInvoicePaidNotification;
use App\Models\Invoice;
use App\Services\EmailNotificationSettingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendAdminInvoicePaidNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $max_exceptions = 2;

    public array $backoff = [30, 60, 120];

    public function __construct(
        public string $invoice_id,
    ) {
        $this->onQueue('emails');
    }

    public function handle(): void
    {
        $invoice = Invoice::with(['user', 'lineItems'])->find($this->invoice_id);

        if (! $invoice) {
            return;
        }

        $recipients   = EmailNotificationSettingService::resolveAdminRecipients();
        $client       = $invoice->user;
        $client_name  = $client?->full_name ?? $client?->email ?? 'Unknown client';
        $client_email = $client?->email ?? '';
        $initials     = $this->buildInitials($client_name);
        $total        = '$' . number_format((float) $invoice->total_amount, 2);

        $line_items = $invoice->lineItems->map(fn ($item) => [
            'name'       => $item->item_name,
            'quantity'   => $item->quantity,
            'item_total' => '$' . number_format((float) $item->item_total, 2),
        ])->toArray();

        $view_invoice_url = rtrim(config('app.admin_url', config('app.frontend_url')), '/') . '/admin/invoices/' . $invoice->id;
        $settings_url     = rtrim(config('app.admin_url', config('app.frontend_url')), '/') . '/admin/email-notifications';
        $payment_date     = $invoice->date_paid?->format('F j, Y \a\t g:i A') ?? now()->format('F j, Y \a\t g:i A');
        $payment_method   = $invoice->payment_method ?? 'Credit Card';

        foreach ($recipients as $position => $recipient) {
            SendEmailJob::dispatchWithThrottle(
                new AdminInvoicePaidNotification(
                    recipient_name:    $recipient['name'],
                    recipient_email:   $recipient['email'],
                    invoice_number:    $invoice->invoice_number,
                    invoice_unique_id: $invoice->unique_id,
                    client_name:       $client_name,
                    client_email:      $client_email,
                    client_initials:   $initials,
                    payment_date:      $payment_date,
                    payment_method:    $payment_method,
                    total_amount:      $total,
                    line_items:        $line_items,
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
        \Log::error('SendAdminInvoicePaidNotificationJob failed', [
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
