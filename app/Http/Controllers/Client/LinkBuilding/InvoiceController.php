<?php

namespace App\Http\Controllers\Client\LinkBuilding;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\LinkBuildingOrder;
use App\Models\User;
use App\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InvoiceController extends Controller
{
    public function __construct(
        protected InvoiceService $invoiceService
    ) {}

    public function index(): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $invoices = Invoice::where('user_id', $user->id)
            ->orderBy('date_issued', 'desc')
            ->get()
            ->map(fn ($invoice) => [
                'unique_id' => $invoice->unique_id,
                'date'      => $invoice->date_issued?->format('F j, Y'),
                'date_due'  => $invoice->date_due?->format('F j, Y'),
                'total'     => $this->formatAmount($invoice->total_amount, $invoice->currency_type),
                'status'    => $invoice->status,
            ]);

        return response()->json(['data' => $invoices]);
    }

    public function show(string $unique_id): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $invoice = Invoice::where('unique_id', $unique_id)
            ->where('user_id', $user->id)
            ->with(['lineItems', 'billedTo', 'order.items.drTier', 'order.items.placements', 'order.billing'])
            ->first();

        if (!$invoice) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

        return response()->json(['data' => $this->buildInvoiceDetail($invoice)]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'order_id'       => ['required', 'uuid'],
            'payment_method' => ['nullable', 'string', Rule::in(Invoice::PAYMENT_METHODS)],
            'currency_type'  => ['nullable', 'string', Rule::in(Invoice::CURRENCY_TYPES)],
            'credit_amount'  => ['nullable', 'numeric', 'min:0'],
        ]);

        /** @var User $user */
        $user  = auth()->user();
        $order = LinkBuildingOrder::where('id', $request->order_id)
            ->where('user_id', $user->id)
            ->with(['items.drTier', 'billing'])
            ->first();

        if (!$order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $existing = Invoice::where('order_id', $order->id)->first();
        if ($existing) {
            return response()->json(['message' => 'An invoice already exists for this order.'], 409);
        }

        $invoice = $this->invoiceService->createForLinkBuildingOrder(
            user:           $user,
            order:          $order,
            payment_method: $request->payment_method ?? 'Account Balance',
            currency_type:  $request->currency_type ?? 'usd',
            credit_amount:  (float) ($request->credit_amount ?? 0),
        );

        $invoice->load(['lineItems', 'billedTo']);

        return response()->json(['data' => $this->buildInvoiceDetail($invoice)], 201);
    }

    private function buildInvoiceDetail(Invoice $invoice): array
    {
        $billed_to = $invoice->billedTo;
        $order     = $invoice->order;

        return [
            'invoice_number' => $invoice->invoice_number,
            'unique_id'      => $invoice->unique_id,
            'date_issued'    => $invoice->date_issued?->format('F j, Y'),
            'date_paid'      => $invoice->date_paid?->format('F j, Y'),
            'date_due'       => $invoice->date_due?->format('F j, Y'),
            'payment_method' => $invoice->payment_method,
            'status'         => $invoice->status,
            'subtotal'       => $this->formatAmount($invoice->subtotal_amount, $invoice->currency_type),
            'total'          => $this->formatAmount($invoice->total_amount, $invoice->currency_type),
            'credit'         => $this->formatCredit($invoice->credit_amount, $invoice->currency_type),
            'billed_to'      => $billed_to ? [
                'company_name'        => $billed_to->company_name,
                'company_description' => $billed_to->company_description,
                'address_line_1'      => $billed_to->address_line_1,
                'address_line_2'      => $billed_to->address_line_2,
                'state'               => $billed_to->state,
                'country'             => $billed_to->country,
            ] : null,
            'line_items' => $invoice->lineItems->map(fn ($item) => [
                'item_name'  => $item->item_name,
                'price'      => $this->formatAmount($item->price, $invoice->currency_type),
                'quantity'   => $item->quantity,
                'item_total' => $this->formatAmount($item->item_total, $invoice->currency_type),
            ]),
            'order' => $order ? [
                'id'          => $order->id,
                'order_title' => $order->order_title,
                'order_notes' => $order->order_notes,
                'status'      => $order->status,
                'created_at'  => $order->created_at?->format('F j, Y'),
                'billing'     => $order->billing ? [
                    'company'     => $order->billing->company,
                    'address'     => $order->billing->address,
                    'city'        => $order->billing->city,
                    'state'       => $order->billing->state,
                    'country'     => $order->billing->country,
                    'postal_code' => $order->billing->postal_code,
                ] : null,
                'items' => $order->items->map(fn ($item) => [
                    'dr_tier'    => $item->drTier ? [
                        'id'             => $item->drTier->id,
                        'dr_label'       => $item->drTier->dr_label,
                        'traffic_range'  => $item->drTier->traffic_range,
                        'word_count'     => $item->drTier->word_count,
                        'price_per_link' => $item->drTier->price_per_link,
                    ] : null,
                    'quantity'   => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'subtotal'   => $item->subtotal,
                    'placements' => $item->placements->map(fn ($placement) => [
                        'row_index'    => $placement->row_index,
                        'keyword'      => $placement->keyword,
                        'landing_page' => $placement->landing_page,
                        'exact_match'  => $placement->exact_match,
                    ]),
                ]),
            ] : null,
        ];
    }

    private function formatAmount(float $amount, string $currency_type): string
    {
        if ($currency_type === 'credits') {
            return (int) $amount . ' credits';
        }

        return '$' . number_format($amount, 2);
    }

    private function formatCredit(float $credit_amount, string $currency_type): string
    {
        if ($credit_amount <= 0) {
            return $currency_type === 'credits' ? '0 credits' : '$0.00';
        }

        if ($currency_type === 'credits') {
            return '-' . (int) $credit_amount . ' credits';
        }

        return '-$' . number_format($credit_amount, 2);
    }
}
