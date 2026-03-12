<?php

namespace App\Http\Controllers\Invoice;

use App\Events\PaymentCompleted;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\LinkBuildingOrder;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class InvoiceController extends Controller
{
    public function index(): JsonResponse
    {
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
        $user = auth()->user();

        $invoice = Invoice::where('unique_id', $unique_id)
            ->where('user_id', $user->id)
            ->with(['lineItems', 'billedTo'])
            ->first();

        if (! $invoice) {
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

        $user  = auth()->user();
        $order = LinkBuildingOrder::where('id', $request->order_id)
            ->where('user_id', $user->id)
            ->with(['items.drTier', 'billing'])
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $existing = Invoice::where('order_id', $order->id)->first();
        if ($existing) {
            return response()->json(['message' => 'An invoice already exists for this order.'], 409);
        }

        $payment_method = $request->payment_method ?? 'Account Balance';
        $currency_type  = $request->currency_type ?? 'usd';
        $credit_amount  = (float) ($request->credit_amount ?? 0);

        $subtotal_amount = $order->items->sum('subtotal');
        $total_amount    = $order->total_amount;

        $invoice = DB::transaction(function () use (
            $user, $order, $payment_method, $currency_type,
            $subtotal_amount, $total_amount, $credit_amount
        ) {
            $unique_id      = strtoupper(bin2hex(random_bytes(4)));
            $invoice_number = 'BSM-' . str_pad(Invoice::count() + 1, 4, '0', STR_PAD_LEFT);

            $invoice = Invoice::create([
                'unique_id'       => $unique_id,
                'invoice_number'  => $invoice_number,
                'user_id'         => $user->id,
                'order_id'        => $order->id,
                'status'          => 'paid',
                'payment_method'  => $payment_method,
                'currency_type'   => $currency_type,
                'subtotal_amount' => $subtotal_amount,
                'total_amount'    => $total_amount,
                'credit_amount'   => $credit_amount,
                'date_issued'     => now(),
                'date_due'        => now()->addDays(30),
                'date_paid'       => now(),
            ]);

            foreach ($order->items as $item) {
                $item_name = $item->drTier
                    ? $item->drTier->dr_label . ' Link Building'
                    : 'Link Building Service';

                $invoice->lineItems()->create([
                    'item_name'  => $item_name,
                    'price'      => $item->unit_price,
                    'quantity'   => $item->quantity,
                    'item_total' => $item->subtotal,
                ]);
            }

            $billing = $order->billing;
            $invoice->billedTo()->create([
                'company_name'        => $billing?->company ?? $user->organization?->name,
                'company_description' => $user->job_title,
                'address_line_1'      => $billing?->address,
                'address_line_2'      => null,
                'state'               => $billing?->state,
                'country'             => $billing?->country,
            ]);

            return $invoice->load(['lineItems', 'billedTo']);
        });

        $payer_name = $user->full_name ?? $user->email;

        User::whereHas('roles', fn ($q) => $q->where('name', 'super_admin'))
            ->each(function (User $admin) use ($invoice, $payer_name, $total_amount) {
                event(new PaymentCompleted(
                    $admin,
                    $payer_name,
                    $total_amount,
                    $invoice->invoice_number,
                    '/invoices/' . $invoice->unique_id,
                ));
            });

        return response()->json(['data' => $this->buildInvoiceDetail($invoice)], 201);
    }

    private function buildInvoiceDetail(Invoice $invoice): array
    {
        $billed_to = $invoice->billedTo;

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
