<?php

namespace App\Http\Controllers\Admin\Order;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\LinkBuildingOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    /**
     * GET /api/admin/orders?page=N
     */
    public function index(Request $request): JsonResponse
    {
        $orders = LinkBuildingOrder::with([
            'user:id,first_name,last_name,email',
            'items',
            'billing',
            'invoice',
            'orderCoupons.coupon',
        ])
            ->latest()
            ->paginate(15);

        $data = $orders->map(function (LinkBuildingOrder $order) {
            return $this->formatOrderSummary($order);
        })->values();

        return response()->json([
            'data'         => $data,
            'current_page' => $orders->currentPage(),
            'last_page'    => $orders->lastPage(),
            'total'        => $orders->total(),
        ]);
    }

    /**
     * GET /api/admin/orders/{order}
     */
    public function show(string $id): JsonResponse
    {
        $order = LinkBuildingOrder::with([
            'user:id,first_name,last_name,email',
            'items.drTier',
            'items.placements',
            'billing',
            'orderCoupons.coupon',
            'invoice.user:id,first_name,last_name,email',
            'invoice.lineItems',
            'invoice.billedTo',
        ])->find($id);

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        return response()->json($this->formatOrderDetail($order));
    }

    private function formatOrderSummary(LinkBuildingOrder $order): array
    {
        $subtotal_before_discount = $order->subtotal_before_discount ?? $order->items->sum('subtotal');

        $user    = $order->user;
        $billing = $order->billing;
        $invoice = $order->invoice;

        return [
            'id'                       => $order->id,
            'user_id'                  => $order->user_id,
            'order_title'              => $order->order_title,
            'order_notes'              => $order->order_notes,
            'subtotal_before_discount' => (float) $subtotal_before_discount,
            'total_amount'             => $order->total_amount,
            'status'                   => $order->status,
            'payment_intent_id'        => $order->payment_intent_id,
            'created_at'               => $order->created_at,
            'updated_at'               => $order->updated_at,
            'user' => $user ? [
                'id'         => $user->id,
                'first_name' => $user->first_name,
                'last_name'  => $user->last_name,
                'email'      => $user->email,
            ] : null,
            'items' => $order->items->map(fn ($item) => [
                'id'         => $item->id,
                'dr_tier_id' => $item->dr_tier_id,
                'quantity'   => $item->quantity,
                'unit_price' => $item->unit_price,
                'subtotal'   => $item->subtotal,
            ])->values(),
            'billing' => $billing ? [
                'company'     => $billing->company,
                'address'     => $billing->address,
                'city'        => $billing->city,
                'state'       => $billing->state,
                'country'     => $billing->country,
                'postal_code' => $billing->postal_code,
            ] : null,
            'invoice' => $invoice ? [
                'id'             => $invoice->id,
                'unique_id'      => $invoice->unique_id,
                'invoice_number' => $invoice->invoice_number,
                'status'         => $invoice->status,
                'total_amount'   => $invoice->total_amount,
            ] : null,
            'coupons' => $this->buildOrderCoupons($order),
        ];
    }

    private function formatOrderDetail(LinkBuildingOrder $order): array
    {
        $subtotal_before_discount = $order->subtotal_before_discount ?? $order->items->sum('subtotal');

        $user    = $order->user;
        $billing = $order->billing;
        $invoice = $order->invoice;

        return [
            'id'                       => $order->id,
            'user_id'                  => $order->user_id,
            'order_title'              => $order->order_title,
            'order_notes'              => $order->order_notes,
            'subtotal_before_discount' => (float) $subtotal_before_discount,
            'total_amount'             => $order->total_amount,
            'status'                   => $order->status,
            'payment_intent_id'        => $order->payment_intent_id,
            'created_at'               => $order->created_at,
            'updated_at'               => $order->updated_at,
            'user' => $user ? [
                'id'         => $user->id,
                'first_name' => $user->first_name,
                'last_name'  => $user->last_name,
                'email'      => $user->email,
            ] : null,
            'items' => $order->items->map(fn ($item) => [
                'id'         => $item->id,
                'dr_tier_id' => $item->dr_tier_id,
                'quantity'   => $item->quantity,
                'unit_price' => $item->unit_price,
                'subtotal'   => $item->subtotal,
                'dr_tier'    => $item->drTier ? [
                    'id'             => $item->drTier->id,
                    'dr_label'       => $item->drTier->dr_label,
                    'traffic_range'  => $item->drTier->traffic_range,
                    'word_count'     => $item->drTier->word_count,
                    'price_per_link' => $item->drTier->price_per_link,
                ] : null,
                'placements' => $item->placements->map(fn ($placement) => [
                    'id'           => $placement->id,
                    'row_index'    => $placement->row_index,
                    'keyword'      => $placement->keyword,
                    'landing_page' => $placement->landing_page,
                    'exact_match'  => $placement->exact_match,
                ])->values(),
            ])->values(),
            'billing' => $billing ? [
                'company'     => $billing->company,
                'address'     => $billing->address,
                'city'        => $billing->city,
                'state'       => $billing->state,
                'country'     => $billing->country,
                'postal_code' => $billing->postal_code,
            ] : null,
            'invoice' => $invoice ? $this->formatInvoiceForOrder($invoice, $order) : null,
            'coupons' => $this->buildOrderCoupons($order),
        ];
    }

    private function formatInvoiceForOrder(Invoice $invoice, LinkBuildingOrder $order): array
    {
        $billed_to = $invoice->billedTo;
        $inv_user  = $invoice->user;

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
            'date_issued'     => $invoice->date_issued?->toIso8601String(),
            'date_due'        => $invoice->date_due?->toIso8601String(),
            'date_paid'       => $invoice->date_paid?->toIso8601String(),
            'created_at'      => $invoice->created_at?->toIso8601String(),
            'updated_at'      => $invoice->updated_at?->toIso8601String(),
            'user' => $inv_user ? [
                'id'         => $inv_user->id,
                'first_name' => $inv_user->first_name,
                'last_name'  => $inv_user->last_name,
                'email'      => $inv_user->email,
            ] : null,
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
            'coupon_discounts' => $this->buildCouponDiscountsFromOrder($order),
        ];
    }

    private function buildOrderCoupons(LinkBuildingOrder $order): array
    {
        return $order->orderCoupons->map(function ($order_coupon) {
            $coupon = $order_coupon->coupon;

            if (! $coupon) {
                return null;
            }

            return [
                'coupon_id'       => $coupon->id,
                'code'            => $coupon->code,
                'name'            => $coupon->name,
                'discount_type'   => $coupon->discount_type,
                'discount_value'  => $coupon->discount_value,
                'discount_amount' => $order_coupon->discount_amount,
            ];
        })->filter()->values()->all();
    }

    private function buildCouponDiscountsFromOrder(?LinkBuildingOrder $order): array
    {
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
