<?php

namespace App\Jobs;

use App\Mail\InvoiceRefundedNotification;
use App\Models\Invoice;
use App\Support\FrontendUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Queues the client-facing refund confirmation email. The numeric breakdown of
 * the refund event is passed in explicitly because the invoice itself only
 * stores the cumulative refunded total, not the amount of this single action.
 */
class SendClientInvoiceRefundedNotificationJob implements ShouldQueue
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
    ) {
        $this->onQueue('emails');
    }

    public function handle(): void
    {
        $invoice = Invoice::with(['user', 'lineItems'])->find($this->invoice_id);

        if (! $invoice || ! $invoice->user || empty($invoice->user->email)) {
            return;
        }

        $client = $invoice->user;

        $line_items = $invoice->lineItems->map(fn ($item) => [
            'name'       => $item->item_name,
            'quantity'   => $item->quantity,
            'item_total' => '$' . number_format((float) $item->item_total, 2),
        ])->toArray();

        $invoice_url  = FrontendUrl::to('/invoices/' . $invoice->unique_id);
        $refund_date  = $invoice->refunded_at?->format('F j, Y \a\t g:i A') ?? now()->format('F j, Y \a\t g:i A');

        $mailable = new InvoiceRefundedNotification(
            recipient_name:  $client->first_name ?? '',
            recipient_email: $client->email,
            invoice_number:  $invoice->invoice_number,
            refund_amount:   '$' . number_format($this->refund_amount, 2),
            total_amount:    '$' . number_format((float) $invoice->total_amount, 2),
            total_refunded:  '$' . number_format($this->total_refunded, 2),
            refund_date:     $refund_date,
            payment_method:  $invoice->payment_method ?? 'Account Balance',
            is_full_refund:  $this->is_full_refund,
            credit_refund:   $this->credit_refund > 0 ? '$' . number_format($this->credit_refund, 2) : null,
            card_refund:     $this->card_refund > 0 ? '$' . number_format($this->card_refund, 2) : null,
            line_items:      $line_items,
            invoice_url:     $invoice_url,
            support_email:   config('mail.from.address') ?? '',
        );

        SendEmailJob::dispatch($mailable, $client->email);
    }

    public function failed(\Throwable $exception): void
    {
        \Log::error('SendClientInvoiceRefundedNotificationJob failed', [
            'invoice_id' => $this->invoice_id,
            'error'      => $exception->getMessage(),
        ]);
    }
}
