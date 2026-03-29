<?php

namespace App\Http\Controllers\Admin\Invoice;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Invoice\ListInvoicesRequest;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;

class InvoiceController extends Controller
{
    /**
     * GET /api/admin/invoices
     */
    public function index(ListInvoicesRequest $request): JsonResponse
    {
        $query = Invoice::with(['user', 'lineItems', 'billedTo', 'order.orderCoupons.coupon'])
            ->join('users', 'invoices.user_id', '=', 'users.id')
            ->select('invoices.*');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('invoices.invoice_number', 'like', "%{$search}%")
                  ->orWhere('users.first_name', 'like', "%{$search}%")
                  ->orWhere('users.last_name', 'like', "%{$search}%")
                  ->orWhere('users.email', 'like', "%{$search}%");
            });
        }

        if ($status = $request->input('status')) {
            $query->where('invoices.status', $status);
        }

        if ($date_from = $request->input('date_from')) {
            $query->whereDate('invoices.date_issued', '>=', $date_from);
        }

        if ($date_to = $request->input('date_to')) {
            $query->whereDate('invoices.date_issued', '<=', $date_to);
        }

        $sort_field     = $request->input('sort_field');
        $sort_direction = $request->input('sort_direction', 'desc');

        if ($sort_field === 'customer') {
            $query->orderBy('users.last_name', $sort_direction)
                  ->orderBy('users.first_name', $sort_direction);
        } elseif ($sort_field) {
            $query->orderBy("invoices.{$sort_field}", $sort_direction);
        } else {
            $query->orderBy('invoices.created_at', $sort_direction);
        }

        $per_page = (int) $request->input('per_page', 15);
        $invoices = $query->paginate($per_page);

        $data = $invoices->map(fn (Invoice $invoice) => $this->formatInvoice($invoice))->values();

        return response()->json([
            'data'         => $data,
            'current_page' => $invoices->currentPage(),
            'last_page'    => $invoices->lastPage(),
            'total'        => $invoices->total(),
        ]);
    }

    /**
     * GET /api/admin/invoices/{unique_id}
     */
    public function show(string $unique_id): JsonResponse
    {
        $invoice = Invoice::where('unique_id', $unique_id)
            ->with(['user', 'lineItems', 'billedTo', 'order.orderCoupons.coupon'])
            ->first();

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

        return response()->json($this->formatInvoice($invoice));
    }

    private function formatInvoice(Invoice $invoice): array
    {
        $billed_to        = $invoice->billedTo;
        $coupon_discounts = $this->buildCouponDiscounts($invoice);

        return [
            'id'              => $invoice->id,
            'unique_id'       => $invoice->unique_id,
            'invoice_number'  => $invoice->invoice_number,
            'user_id'         => $invoice->user_id,
            'order_id'        => $invoice->order_id,
            'status'          => $invoice->status,
            'payment_method'  => $invoice->payment_method,
            'currency_type'   => $invoice->currency_type,
            'subtotal_amount' => $invoice->subtotal_amount,
            'discount_amount' => $invoice->discount_amount,
            'total_amount'    => $invoice->total_amount,
            'credit_amount'   => $invoice->credit_amount,
            'date_issued'     => $invoice->date_issued?->toDateString(),
            'date_due'        => $invoice->date_due?->toDateString(),
            'date_paid'       => $invoice->date_paid?->toDateString(),
            'created_at'      => $invoice->created_at?->toIso8601String(),
            'updated_at'      => $invoice->updated_at?->toIso8601String(),
            'user' => [
                'id'         => $invoice->user->id,
                'first_name' => $invoice->user->first_name,
                'last_name'  => $invoice->user->last_name,
                'email'      => $invoice->user->email,
            ],
            'line_items' => $invoice->lineItems->map(fn ($item) => [
                'id'         => $item->id,
                'item_name'  => $item->item_name,
                'price'      => $item->price,
                'quantity'   => $item->quantity,
                'item_total' => $item->item_total,
            ])->values(),
            'billed_to' => $billed_to ? [
                'company_name'        => $billed_to->company_name,
                'company_description' => $billed_to->company_description,
                'address_line_1'      => $billed_to->address_line_1,
                'address_line_2'      => $billed_to->address_line_2,
                'state'               => $billed_to->state,
                'country'             => $billed_to->country,
            ] : null,
            'coupon_discounts' => $coupon_discounts,
        ];
    }

    private function buildCouponDiscounts(Invoice $invoice): array
    {
        $order = $invoice->relationLoaded('order') ? $invoice->order : null;

        if (! $order) {
            return [];
        }

        return $order->orderCoupons->map(function ($order_coupon) {
            $coupon = $order_coupon->coupon;

            if (! $coupon) {
                return null;
            }

            return [
                'code'            => $coupon->code,
                'name'            => $coupon->name,
                'discount_type'   => $coupon->discount_type,
                'discount_value'  => $coupon->discount_value,
                'discount_amount' => $order_coupon->discount_amount,
            ];
        })->filter()->values()->all();
    }
}
