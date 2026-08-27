<?php

namespace App\Notifications;

use App\Models\Invoice;
use App\Models\User;
use App\Support\FrontendUrl;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvoiceCreatedNotification extends Notification
{
    public function __construct(
        public readonly Invoice $invoice,
        public readonly User $client,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $invoice_url = FrontendUrl::to('/invoices/' . $this->invoice->unique_id);
        $line_items  = $this->invoice->lineItems->map(fn ($item) => [
            'name'       => $item->item_name,
            'price'      => $item->price,
            'quantity'   => $item->quantity,
            'item_total' => $item->item_total,
        ])->toArray();

        return (new MailMessage())
            ->subject("Invoice {$this->invoice->invoice_number} from " . config('app.name'))
            ->view('emails.invoice-created', [
                'user_name'       => $this->client->first_name,
                'user_email'      => $this->client->email,
                'invoice_number'  => $this->invoice->invoice_number,
                'invoice_url'     => $invoice_url,
                'subtotal_amount' => $this->invoice->subtotal_amount ?? $this->invoice->total_amount,
                'discount_amount' => $this->invoice->discount_amount ?? 0,
                'total_amount'    => $this->invoice->total_amount,
                'currency_type'   => $this->invoice->currency_type,
                'date_due'        => $this->invoice->date_due?->format('F j, Y'),
                'line_items'      => $line_items,
                'notes'           => $this->invoice->notes,
                'app_name'        => config('app.name'),
            ]);
    }
}
