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
            ->with(['lineItems', 'billedTo', 'order.orderCoupons.coupon'])
            ->first();

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

        $token = $request->query('token');

        if (! $invoice->sharing_enabled) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        if (! $token || $token !== $invoice->share_key) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        return response()->json(['data' => $this->formatPublicInvoice($invoice)]);
    }

    private function formatPublicInvoice(Invoice $invoice): array
    {
        $is_usd    = $invoice->currency_type === 'usd';
        $billed_to = $invoice->billedTo;
        $coupons   = $this->buildCouponDiscounts($invoice, $is_usd);

        $data = [
            'invoice_number' => $invoice->invoice_number,
            'unique_id'      => $invoice->unique_id,
            'date_issued'    => $invoice->date_issued?->format('M j, Y'),
            'date_paid'      => $invoice->date_paid?->format('M j, Y'),
            'date_due'       => $invoice->date_due?->format('M j, Y'),
            'payment_method' => $invoice->payment_method,
            'status'         => $invoice->status,
            'subtotal'       => $this->formatMoney($invoice->subtotal_amount, $is_usd),
            'total'          => $this->formatMoney($invoice->total_amount, $is_usd),
            'credit'         => $this->formatMoney($invoice->credit_amount, $is_usd),
            'billed_to'      => $billed_to ? [
                'company_name'        => $billed_to->company_name,
                'company_description' => $billed_to->company_description,
                'address_line_1'      => $billed_to->address_line_1,
                'address_line_2'      => $billed_to->address_line_2,
                'state'               => $billed_to->state,
                'country'             => $billed_to->country,
            ] : null,
            'line_items'     => $invoice->lineItems->map(fn ($item) => [
                'item_name'  => $item->item_name,
                'price'      => $this->formatMoney((float) $item->price, $is_usd),
                'quantity'   => $item->quantity,
                'item_total' => $this->formatMoney((float) $item->item_total, $is_usd),
            ])->values()->all(),
        ];

        if ($invoice->discount_amount > 0) {
            $data['discount'] = $this->formatMoney($invoice->discount_amount, $is_usd);
        }

        if (! empty($coupons)) {
            $data['coupon_discounts'] = $coupons;
        }

        return $data;
    }

    private function formatMoney(float $amount, bool $is_usd): string
    {
        if ($is_usd) {
            return '$' . number_format($amount, 2);
        }

        return number_format($amount, 0) . ' credits';
    }

    private function buildCouponDiscounts(Invoice $invoice, bool $is_usd): array
    {
        $order = $invoice->relationLoaded('order') ? $invoice->order : null;

        if (! $order) {
            return [];
        }

        return $order->orderCoupons->map(function ($order_coupon) use ($is_usd) {
            $coupon = $order_coupon->coupon;

            if (! $coupon) {
                return null;
            }

            return [
                'code'            => $coupon->code,
                'name'            => $coupon->name,
                'discount_type'   => $coupon->discount_type,
                'discount_value'  => $coupon->discount_value,
                'discount_amount' => $this->formatMoney((float) $order_coupon->discount_amount, $is_usd),
            ];
        })->filter()->values()->all();
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

        if (!$invoice) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

        $payment_intent_id = $request->input('payment_intent_id');
        $token = $request->input('token');

        $result = $payment_service->confirmPublicInvoicePayment(
            $invoice,
            $payment_intent_id,
            $token
        );

        $status_code = $result['status_code'] ?? 200;

        if ($result['success']) {
            return response()->json([
                'message' => $result['message'],
                'status'  => $result['status'],
            ], $status_code);
        }

        return response()->json(['message' => $result['error']], $status_code);
    }
}
