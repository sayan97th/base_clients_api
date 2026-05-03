<?php

namespace App\Http\Controllers\Admin\NewContent;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\NewContent\UpdateNewContentOrderStatusRequest;
use App\Models\Invoice;
use App\Models\NewContentOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminNewContentOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $per_page = min((int) $request->input('per_page', 25), 100);

        $query = NewContentOrder::with([
            'user:id,first_name,last_name,email',
            'items.tier:id,label',
            'items.intakeRows',
            'billing',
        ]);

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->whereHas('user', function ($u) use ($search) {
                    $u->where('first_name', 'like', "%{$search}%")
                      ->orWhere('last_name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                })->orWhereHas('items.intakeRows', function ($r) use ($search) {
                    $r->where('keyword_phrase', 'like', "%{$search}%");
                });
            });
        }

        if ($request->filled('client_id')) {
            $query->where('user_id', $request->input('client_id'));
        }

        if ($request->filled('tier_id')) {
            $query->whereHas('items', function ($q) use ($request) {
                $q->where('tier_id', $request->input('tier_id'));
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        $orders = $query->orderBy('created_at', 'desc')->paginate($per_page);

        $data = $orders->map(function (NewContentOrder $order) {
            return $this->formatOrderSummary($order);
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $orders->currentPage(),
                'per_page'     => $orders->perPage(),
                'total'        => $orders->total(),
                'last_page'    => $orders->lastPage(),
            ],
        ]);
    }

    public function show(string $order_id): JsonResponse
    {
        $order = NewContentOrder::with([
            'user:id,first_name,last_name,email',
            'items.tier:id,label',
            'items.intakeRows',
            'billing',
        ])->find($order_id);

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        return response()->json($this->formatOrderDetail($order));
    }

    public function updateStatus(UpdateNewContentOrderStatusRequest $request, string $order_id): JsonResponse
    {
        $order = NewContentOrder::find($order_id);

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $order->update(['status' => $request->input('status')]);

        $order->load([
            'user:id,first_name,last_name,email',
            'items.tier',
            'items.intakeRows',
            'billing',
            'orderCoupons.coupon',
            'invoice.user:id,first_name,last_name,email',
            'invoice.lineItems',
            'invoice.billedTo',
            'invoice.couponDiscounts',
        ]);

        return response()->json($this->buildFullOrderResponse($order));
    }

    private function buildFullOrderResponse(NewContentOrder $order): array
    {
        $user    = $order->user;
        $billing = $order->billing;
        $invoice = $order->invoice;

        return [
            'id'                       => $order->id,
            'user_id'                  => $order->user_id,
            'order_title'              => $order->order_title,
            'order_notes'              => $order->order_notes,
            'subtotal_before_discount' => (float) ($order->subtotal_before_discount ?? $order->items->sum('subtotal')),
            'total_amount'             => (float) $order->total_amount,
            'status'                   => $order->status,
            'payment_intent_id'        => $order->payment_intent_id,
            'product_type'             => 'new_content',
            'session_id'               => $order->session_id,
            'session_title'            => $order->session_title,
            'created_at'               => $order->created_at,
            'updated_at'               => $order->updated_at,
            'user' => $user ? [
                'id'         => $user->id,
                'first_name' => $user->first_name,
                'last_name'  => $user->last_name,
                'email'      => $user->email,
            ] : null,
            'items'   => $order->items->map(function ($item) {
                $tier_label = $item->tier?->label;
                $item_name  = $tier_label ? "New Content – {$tier_label}" : 'New Content';

                return [
                    'id'          => $item->id,
                    'dr_tier_id'  => null,
                    'quantity'    => $item->quantity,
                    'unit_price'  => (float) $item->unit_price,
                    'subtotal'    => (float) $item->subtotal,
                    'item_name'   => $item_name,
                    'intake_rows' => $item->intakeRows->map(fn ($row) => [
                        'keyword_phrase'  => $row->keyword_phrase,
                        'type_of_content' => $row->type_of_content,
                        'notes'           => $row->notes,
                    ])->values()->all(),
                ];
            })->values()->all(),
            'billing' => $billing ? [
                'company'     => $billing->company,
                'address'     => $billing->address,
                'city'        => $billing->city,
                'state'       => $billing->state,
                'country'     => $billing->country,
                'postal_code' => $billing->postal_code,
            ] : null,
            'invoice' => $invoice ? $this->buildInvoiceData($invoice, $order) : null,
            'coupons' => $this->buildCoupons($order),
        ];
    }

    private function buildInvoiceData(Invoice $invoice, NewContentOrder $order): array
    {
        $billed_to = $invoice->billedTo;
        $inv_user  = $invoice->user;

        return [
            'id'               => $invoice->id,
            'unique_id'        => $invoice->unique_id,
            'invoice_number'   => $invoice->invoice_number,
            'user_id'          => $invoice->user_id,
            'order_id'         => $invoice->order_id,
            'status'           => $invoice->status,
            'payment_method'   => $invoice->payment_method,
            'currency_type'    => $invoice->currency_type,
            'subtotal_amount'  => $invoice->subtotal_amount,
            'discount_amount'  => $invoice->discount_amount,
            'discount_type'    => $invoice->discount_type ?? null,
            'total_amount'     => $invoice->total_amount,
            'credit_amount'    => $invoice->credit_amount,
            'date_issued'      => $invoice->date_issued?->toDateString(),
            'date_due'         => $invoice->date_due?->toDateString(),
            'date_paid'        => $invoice->date_paid?->toDateString(),
            'created_at'       => $invoice->created_at,
            'updated_at'       => $invoice->updated_at,
            'user' => $inv_user ? [
                'id'         => $inv_user->id,
                'first_name' => $inv_user->first_name,
                'last_name'  => $inv_user->last_name,
                'email'      => $inv_user->email,
            ] : null,
            'line_items' => $invoice->lineItems->map(fn ($li) => [
                'id'         => $li->id,
                'item_name'  => $li->item_name,
                'price'      => $li->price,
                'quantity'   => $li->quantity,
                'item_total' => $li->item_total,
            ])->values()->all(),
            'billed_to' => $billed_to ? [
                'company_name'        => $billed_to->company_name,
                'company_description' => $billed_to->company_description,
                'address_line_1'      => $billed_to->address_line_1,
                'address_line_2'      => $billed_to->address_line_2,
                'state'               => $billed_to->state,
                'country'             => $billed_to->country,
            ] : null,
            'coupon_discounts' => $this->buildCoupons($order),
        ];
    }

    private function buildCoupons(NewContentOrder $order): array
    {
        if (! $order->relationLoaded('orderCoupons')) {
            return [];
        }

        return $order->orderCoupons->map(function ($oc) {
            $coupon = $oc->coupon;
            if (! $coupon) {
                return null;
            }

            return [
                'coupon_id'       => $coupon->id,
                'code'            => $coupon->code,
                'name'            => $coupon->name,
                'discount_type'   => $coupon->discount_type,
                'discount_value'  => $coupon->discount_value,
                'discount_amount' => $oc->discount_amount,
            ];
        })->filter()->values()->all();
    }

    private function formatOrderSummary(NewContentOrder $order): array
    {
        return [
            'order_id'    => $order->id,
            'order_title' => $order->order_title,
            'order_notes' => $order->order_notes,
            'admin_notes' => $order->admin_notes,
            'status'      => $order->status,
            'total_amount' => $order->total_amount,
            'created_at'  => $order->created_at,
            'client'      => $order->user ? [
                'client_id' => $order->user->id,
                'name'      => trim($order->user->first_name . ' ' . $order->user->last_name),
                'email'     => $order->user->email,
                'company'   => $order->billing?->company,
            ] : null,
            'new_content_items' => $order->items->map(function ($item) {
                return [
                    'item_id'           => $item->id,
                    'tier_id'           => $item->tier_id,
                    'tier_name'         => $item->tier?->label,
                    'quantity'          => $item->quantity,
                    'unit_price'        => $item->unit_price,
                    'intake_rows_count' => $item->intakeRows->count(),
                    'intake_rows'       => $item->intakeRows->map(fn ($row) => $this->formatRow($row))->values(),
                ];
            })->values(),
        ];
    }

    private function formatOrderDetail(NewContentOrder $order): array
    {
        return [
            'order_id'    => $order->id,
            'order_title' => $order->order_title,
            'order_notes' => $order->order_notes,
            'admin_notes' => $order->admin_notes,
            'status'      => $order->status,
            'total_amount' => $order->total_amount,
            'created_at'  => $order->created_at,
            'client'      => $order->user ? [
                'client_id' => $order->user->id,
                'name'      => trim($order->user->first_name . ' ' . $order->user->last_name),
                'email'     => $order->user->email,
                'company'   => $order->billing?->company,
            ] : null,
            'billing'     => $order->billing ? [
                'company'     => $order->billing->company,
                'address'     => $order->billing->address,
                'city'        => $order->billing->city,
                'state'       => $order->billing->state,
                'country'     => $order->billing->country,
                'postal_code' => $order->billing->postal_code,
            ] : null,
            'new_content_items' => $order->items->map(function ($item) {
                return [
                    'item_id'     => $item->id,
                    'tier_id'     => $item->tier_id,
                    'tier_name'   => $item->tier?->label,
                    'quantity'    => $item->quantity,
                    'unit_price'  => $item->unit_price,
                    'intake_rows' => $item->intakeRows->map(fn ($row) => $this->formatRow($row))->values(),
                ];
            })->values(),
        ];
    }

    private function formatRow(mixed $row): array
    {
        return [
            'row_id'          => $row->id,
            'row_index'       => $row->row_index,
            'keyword_phrase'  => $row->keyword_phrase,
            'type_of_content' => $row->type_of_content,
            'notes'           => $row->notes,
            'status'          => $row->status,
            'updated_at'      => $row->updated_at,
        ];
    }
}
