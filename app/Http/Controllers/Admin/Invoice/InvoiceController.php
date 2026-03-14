<?php

namespace App\Http\Controllers\Admin\Invoice;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    /**
     * GET /api/admin/invoices?page=N
     */
    public function index(Request $request): JsonResponse
    {
        $invoices = Invoice::with(['user', 'lineItems', 'billedTo'])
            ->latest()
            ->paginate(15);

        return response()->json([
            'data' => $invoices->items(),
            'current_page' => $invoices->currentPage(),
            'last_page' => $invoices->lastPage(),
            'total' => $invoices->total(),
        ]);
    }

    /**
     * GET /api/admin/invoices/{invoice_id}
     */
    public function show(string $invoice_id): JsonResponse
    {
        $invoice = Invoice::where('unique_id', $invoice_id)
            ->with(['user', 'lineItems', 'billedTo'])
            ->first();

        if (!$invoice) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

        $billed_to = $invoice->billedTo;

        return response()->json([
            'id'             => $invoice->id,
            'unique_id'      => $invoice->unique_id,
            'invoice_number' => $invoice->invoice_number,
            'user_id'        => $invoice->user_id,
            'order_id'       => $invoice->order_id,
            'status'         => $invoice->status,
            'payment_method' => $invoice->payment_method,
            'currency_type'  => $invoice->currency_type,
            'subtotal_amount' => $invoice->subtotal_amount,
            'total_amount'   => $invoice->total_amount,
            'credit_amount'  => $invoice->credit_amount,
            'date_issued'    => $invoice->date_issued?->toIso8601String(),
            'date_due'       => $invoice->date_due?->toIso8601String(),
            'date_paid'      => $invoice->date_paid?->toIso8601String(),
            'created_at'     => $invoice->created_at?->toIso8601String(),
            'updated_at'     => $invoice->updated_at?->toIso8601String(),
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
        ]);
    }
}