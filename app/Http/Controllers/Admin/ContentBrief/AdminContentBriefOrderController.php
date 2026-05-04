<?php

namespace App\Http\Controllers\Admin\ContentBrief;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ContentBrief\UpdateContentBriefOrderStatusRequest;
use App\Models\ContentBriefOrder;
use Illuminate\Http\JsonResponse;

class AdminContentBriefOrderController extends Controller
{
    public function updateStatus(UpdateContentBriefOrderStatusRequest $request, string $order_id): JsonResponse
    {
        $order = ContentBriefOrder::find($order_id);

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $order->update(['status' => $request->input('status')]);

        $order->load([
            'user:id,first_name,last_name,email',
            'billing',
            'items.tier',
            'items.intakeRows',
            'orderCoupons.coupon',
        ]);

        $user    = $order->user;
        $billing = $order->billing;

        return response()->json([
            'id'               => $order->id,
            'user_id'          => $order->user_id,
            'order_title'      => $order->order_title,
            'order_notes'      => $order->order_notes,
            'total_amount'     => (float) $order->total_amount,
            'status'           => $order->status,
            'payment_intent_id' => $order->payment_intent_id,
            'created_at'       => $order->created_at,
            'updated_at'       => $order->updated_at,
            'product_type'     => 'content_brief',
            'user' => $user ? [
                'id'         => $user->id,
                'first_name' => $user->first_name,
                'last_name'  => $user->last_name,
                'email'      => $user->email,
            ] : null,
            'billing' => $billing ? [
                'company'     => $billing->company,
                'address'     => $billing->address,
                'city'        => $billing->city,
                'state'       => $billing->state,
                'country'     => $billing->country,
                'postal_code' => $billing->postal_code,
            ] : null,
            'invoice' => null,
            'coupons' => $order->orderCoupons->map(function ($oc) {
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
            })->filter()->values()->all(),
            'items' => $order->items->map(fn ($item) => [
                'id'             => $item->id,
                'quantity'       => $item->quantity,
                'unit_price'     => (float) $item->unit_price,
                'subtotal'       => (float) $item->subtotal,
                'item_name'      => $item->tier?->label ? 'Content Brief – ' . $item->tier->label : 'Content Brief',
                'co_intake_rows' => $item->intakeRows->map(fn ($row) => [
                    'primary_keyword'    => $row->primary_keyword,
                    'secondary_keywords' => $row->secondary_keywords,
                    'content_page_url'   => $row->content_page_url,
                ])->values()->all(),
            ])->values()->all(),
        ]);
    }
}
