<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentSuccessfulEmail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param User|null $user - Real User model (can be null for test data)
     * @param Invoice|null $invoice - Real Invoice model (can be null for test data)
     * @param array|null $test_data - Test data array if models are not available
     */
    public function __construct(
        public ?User $user = null,
        public ?Invoice $invoice = null,
        public ?array $test_data = null,
    ) {
        $this->onQueue('emails');
    }

    public function envelope(): Envelope
    {
        $invoice_number = $this->invoice?->invoice_number ?? $this->test_data['invoice_number'] ?? 'N/A';

        return new Envelope(
            subject: "Payment Confirmed — Invoice {$invoice_number} - " . config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.payment-successful',
            with: $this->buildPaymentData(),
        );
    }

    protected function buildPaymentData(): array
    {
        // If test data is provided, use it directly
        if ($this->test_data) {
            return $this->test_data;
        }

        // Otherwise, build from real models
        if (!$this->invoice || !$this->user) {
            return [];
        }

        $invoice = $this->invoice->loadMissing(['lineItems', 'billedTo', 'order.orderCoupons.coupon']);
        $is_credits = $invoice->currency_type === 'credits';
        $invoice_url = config('app.frontend_url') . '/invoices/' . $invoice->unique_id;

        $line_items = $invoice->lineItems->map(fn ($item) => [
            'name'        => $item->item_name,
            'description' => $item->description ?? null,
            'price'       => $item->price,
            'quantity'    => $item->quantity,
            'item_total'  => $item->item_total,
        ])->toArray();

        $coupon_discounts = $this->buildCouponDiscounts($invoice, $is_credits);
        $billed_to = $this->formatBilledTo($invoice);

        return [
            'user_name'        => $this->user->full_name,
            'user_email'       => $this->user->email,
            'invoice_number'   => $invoice->invoice_number,
            'invoice_url'      => $invoice_url,
            'payment_date'     => $invoice->date_paid?->format('F j, Y \a\t g:i A') ?? now()->format('F j, Y \a\t g:i A'),
            'payment_method'   => $invoice->payment_method ?? 'Credit Card',
            'currency_type'    => $invoice->currency_type,
            'is_credits'       => $is_credits,
            'subtotal_amount'  => $invoice->subtotal_amount,
            'discount_amount'  => $invoice->discount_amount ?? 0,
            'credit_amount'    => $invoice->credit_amount ?? 0,
            'total_amount'     => $invoice->total_amount,
            'line_items'       => $line_items,
            'coupon_discounts' => $coupon_discounts,
            'billed_to'        => $billed_to,
            'app_name'         => config('app.name'),
        ];
    }

    protected function buildCouponDiscounts(Invoice $invoice, bool $is_credits): array
    {
        $order = $invoice->relationLoaded('order') ? $invoice->order : null;

        if (!$order || $order->orderCoupons->isEmpty()) {
            return [];
        }

        return $order->orderCoupons->map(function ($order_coupon) use ($is_credits) {
            $coupon = $order_coupon->coupon;

            if (!$coupon) {
                return null;
            }

            $discount_amount = $is_credits
                ? number_format($order_coupon->discount_amount)
                : '$' . number_format($order_coupon->discount_amount, 2);

            return [
                'code'              => $coupon->code,
                'name'              => $coupon->name,
                'discount_type'     => $coupon->discount_type,
                'discount_value'    => $coupon->discount_value,
                'discount_amount'   => $discount_amount,
            ];
        })->filter()->values()->toArray();
    }

    protected function formatBilledTo(Invoice $invoice): ?array
    {
        $billed_to = $invoice->billedTo;

        if (!$billed_to) {
            return null;
        }

        return [
            'company_name'        => $billed_to->company_name,
            'company_description' => $billed_to->company_description,
            'address_line_1'      => $billed_to->address_line_1,
            'address_line_2'      => $billed_to->address_line_2,
            'state'               => $billed_to->state,
            'country'             => $billed_to->country,
        ];
    }
}
