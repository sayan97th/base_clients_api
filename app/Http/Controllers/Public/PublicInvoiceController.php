<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\PayInvoiceRequest;
use App\Models\Invoice;
use App\Services\StripePublicPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicInvoiceController extends Controller
{
    /**
     * GET /api/invoices/{invoice_id}/view
     */
    public function show(Request $request, string $invoice_id): JsonResponse
    {
        $invoice = Invoice::where('unique_id', $invoice_id)
            ->with(['lineItems', 'billedTo', 'couponDiscounts'])
            ->first();

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

        $token = $request->query('token');

        if (! $token) {
            return response()->json(['message' => 'Token is required.'], 401);
        }

        if (! $invoice->sharing_enabled || $token !== $invoice->share_key) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        return response()->json(['data' => $this->buildInvoiceDetail($invoice)]);
    }

    /**
     * POST /api/invoices/{invoice_id}/pay
     *
     * Confirm a Stripe payment for a public invoice.
     * No authentication required; authorization via token in request body.
     */
    public function pay(
        PayInvoiceRequest $request,
        string $invoice_id,
        StripePublicPaymentService $payment_service
    ): JsonResponse {
        $invoice = Invoice::where('unique_id', $invoice_id)->first();

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

        $payment_intent_id = $request->input('payment_intent_id');
        $token             = $request->input('token');

        $result      = $payment_service->confirmPublicInvoicePayment($invoice, $payment_intent_id, $token);
        $status_code = $result['status_code'] ?? 200;

        if (! $result['success']) {
            return response()->json(['message' => $result['error']], $status_code);
        }

        $invoice->refresh()->load(['lineItems', 'billedTo', 'couponDiscounts']);

        return response()->json([
            'data'    => $this->buildInvoiceDetail($invoice),
            'message' => $result['message'],
        ]);
    }

    private function buildInvoiceDetail(Invoice $invoice): array
    {
        $is_usd        = $invoice->currency_type === 'usd';
        $billed_to     = $invoice->billedTo;
        $bulk_discount = (float) ($invoice->discount_amount ?? 0);

        $coupon_discounts = $invoice->couponDiscounts->map(fn ($cd) => [
            'code'            => $cd->code,
            'name'            => $cd->name ?? '',
            'discount_type'   => $cd->discount_type,
            'discount_value'  => $cd->discount_value,
            'discount_amount' => $this->formatMoney((float) $cd->discount_amount, $is_usd),
        ])->values()->all();

        return [
            'invoice_number'   => $invoice->invoice_number,
            'unique_id'        => $invoice->unique_id,
            'date_issued'      => $invoice->date_issued?->format('M j, Y'),
            'date_paid'        => $invoice->date_paid?->format('M j, Y'),
            'date_due'         => $invoice->date_due?->format('M j, Y'),
            'payment_method'   => $invoice->payment_method,
            'status'           => $invoice->status,
            'subtotal'         => $this->formatMoney((float) $invoice->subtotal_amount, $is_usd),
            'discount'         => $bulk_discount > 0 ? $this->formatMoney($bulk_discount, $is_usd) : null,
            'discount_type'    => $invoice->discount_type,
            'total'            => $this->formatMoney((float) $invoice->total_amount, $is_usd),
            'credit'           => $this->formatMoney((float) ($invoice->credit_amount ?? 0), $is_usd),
            'notes'            => $invoice->notes,
            'billed_to'        => $billed_to ? [
                'company_name'        => $billed_to->company_name,
                'company_description' => $billed_to->company_description,
                'address_line_1'      => $billed_to->address_line_1,
                'address_line_2'      => $billed_to->address_line_2,
                'state'               => $billed_to->state,
                'country'             => $billed_to->country,
            ] : null,
            'line_items'       => $invoice->lineItems->map(fn ($item) => [
                'item_name'    => $item->item_name,
                'price'        => $this->formatMoney((float) $item->price, $is_usd),
                'quantity'     => $item->quantity,
                'item_total'   => $this->formatMoney((float) $item->item_total, $is_usd),
                'product_type' => $item->product_type,
            ])->values()->all(),
            'coupon_discounts' => $coupon_discounts,
        ];
    }

    private function formatMoney(float $amount, bool $is_usd): string
    {
        if ($is_usd) {
            return '$' . number_format($amount, 2);
        }

        return number_format($amount, 0) . ' credits';
    }
}
