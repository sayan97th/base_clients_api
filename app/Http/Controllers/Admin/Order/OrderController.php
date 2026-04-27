<?php

namespace App\Http\Controllers\Admin\Order;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Order\IndexOrderRequest;
use App\Mail\OrderStatusChangeMail;
use App\Models\ContentBriefOrder;
use App\Models\ContentOptimizationOrder;
use App\Models\Invoice;
use App\Models\LinkBuildingOrder;
use App\Models\NewContentOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class OrderController extends Controller
{
    private const PRODUCT_TYPES = [
        'link_building',
        'new_content',
        'content_optimization',
        'content_brief',
    ];

    private const MODEL_MAP = [
        'link_building'        => LinkBuildingOrder::class,
        'new_content'          => NewContentOrder::class,
        'content_optimization' => ContentOptimizationOrder::class,
        'content_brief'        => ContentBriefOrder::class,
    ];

    /**
     * GET /api/admin/orders
     *
     * Returns a paginated, filtered, and sortable list of all orders across all product types.
     * The frontend groups orders client-side by session_id to display multi-product purchases
     * as a single visual unit.
     */
    public function index(IndexOrderRequest $request): JsonResponse
    {
        $search         = $request->input('search');
        $status         = $request->input('status');
        $sort_field     = $request->input('sort_field', 'created_at');
        $sort_direction = $request->input('sort_direction', 'desc');
        $date_from      = $request->input('date_from');
        $date_to        = $request->input('date_to');
        $per_page       = (int) $request->input('per_page', 15);

        $cols = implode(', ', [
            'id', 'user_id', 'order_title', 'order_notes',
            'subtotal_before_discount', 'total_amount', 'status',
            'payment_intent_id', 'session_id', 'session_title',
            'created_at', 'updated_at',
        ]);

        $union_sql = implode(' UNION ALL ', [
            "SELECT {$cols}, 'link_building' AS product_type FROM link_building_orders",
            "SELECT {$cols}, 'new_content' AS product_type FROM new_content_orders",
            "SELECT {$cols}, 'content_optimization' AS product_type FROM content_optimization_orders",
            "SELECT {$cols}, 'content_brief' AS product_type FROM content_brief_orders",
        ]);

        $query = DB::table(DB::raw("({$union_sql}) AS all_orders"))
            ->join('users', 'users.id', '=', 'all_orders.user_id')
            ->select('all_orders.*');

        if (filled($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('all_orders.order_title', 'like', '%' . $search . '%')
                  ->orWhere('all_orders.order_notes', 'like', '%' . $search . '%')
                  ->orWhere('all_orders.id', 'like', '%' . $search . '%')
                  ->orWhere('users.first_name', 'like', '%' . $search . '%')
                  ->orWhere('users.last_name', 'like', '%' . $search . '%')
                  ->orWhere('users.email', 'like', '%' . $search . '%');
            });
        }

        if (filled($status)) {
            $query->where('all_orders.status', $status);
        }

        if (filled($date_from)) {
            $query->whereDate('all_orders.created_at', '>=', $date_from);
        }

        if (filled($date_to)) {
            $query->whereDate('all_orders.created_at', '<=', $date_to);
        }

        if ($sort_field === 'customer') {
            $query->orderBy('users.first_name', $sort_direction)
                  ->orderBy('users.last_name', $sort_direction);
        } else {
            $allowed = ['created_at', 'total_amount', 'status', 'order_title'];
            $col     = in_array($sort_field, $allowed) ? $sort_field : 'created_at';
            $query->orderBy('all_orders.' . $col, $sort_direction);
        }

        $query->orderBy('all_orders.id', 'desc');

        $paginated = $query->paginate($per_page);

        $ids_by_type = array_fill_keys(self::PRODUCT_TYPES, []);
        foreach ($paginated->items() as $row) {
            $ids_by_type[$row->product_type][] = $row->id;
        }

        $models_by_id = $this->loadOrderModels($ids_by_type);

        $data = collect($paginated->items())
            ->map(fn ($row) => isset($models_by_id[$row->id])
                ? $this->formatOrderDetail($models_by_id[$row->id], $row->product_type)
                : null)
            ->filter()
            ->values();

        return response()->json([
            'data'         => $data,
            'current_page' => $paginated->currentPage(),
            'last_page'    => $paginated->lastPage(),
            'total'        => $paginated->total(),
        ]);
    }

    /**
     * GET /api/admin/orders/{order_id}
     *
     * Returns full detail for a single order looked up across all product types.
     */
    public function show(string $order_id): JsonResponse
    {
        [$order, $product_type] = $this->findOrder($order_id);

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $this->loadRelations($order, $product_type);

        return response()->json($this->formatOrderDetail($order, $product_type));
    }

    /**
     * PATCH /api/admin/orders/{order}/status
     *
     * Updates the status of a link-building order and optionally notifies the client.
     */
    public function updateStatus(Request $request, LinkBuildingOrder $order): JsonResponse
    {
        $order->load('user');

        $validated = $request->validate([
            'status'      => ['required', 'string', 'in:' . implode(',', LinkBuildingOrder::STATUSES)],
            'notify_user' => ['nullable', 'boolean'],
        ]);

        $order->update(['status' => $validated['status']]);

        if (($validated['notify_user'] ?? false) && $order->user) {
            Mail::to($order->user->email)->queue(
                new OrderStatusChangeMail(
                    user: $order->user,
                    new_status: $order->status,
                    order_id: $order->id,
                )
            );
        }

        return response()->json([
            'message' => 'Order status updated to ' . $validated['status'] . '.',
            'status'  => $order->status,
        ]);
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function findOrder(string $order_id): array
    {
        foreach (self::MODEL_MAP as $product_type => $model_class) {
            $order = $model_class::find($order_id);
            if ($order) {
                return [$order, $product_type];
            }
        }

        return [null, null];
    }

    private function loadRelations(mixed $order, string $product_type): void
    {
        $shared_invoice_relations = [
            'invoice.user:id,first_name,last_name,email',
            'invoice.lineItems',
            'invoice.billedTo',
            'invoice.couponDiscounts',
        ];

        if ($product_type === 'link_building') {
            $order->load(array_merge([
                'user:id,first_name,last_name,email',
                'items.drTier',
                'items.placements',
                'billing',
                'orderCoupons.coupon',
            ], $shared_invoice_relations));
        } else {
            $order->load(array_merge([
                'user:id,first_name,last_name,email',
                'items.tier',
                'billing',
                'orderCoupons.coupon',
            ], $shared_invoice_relations));
        }
    }

    private function loadOrderModels(array $ids_by_type): array
    {
        $models_by_id = [];

        $shared_invoice_withs = [
            'invoice.user:id,first_name,last_name,email',
            'invoice.lineItems',
            'invoice.billedTo',
            'invoice.couponDiscounts',
        ];

        $lb_ids = $ids_by_type['link_building'] ?? [];
        if (! empty($lb_ids)) {
            LinkBuildingOrder::whereIn('id', $lb_ids)
                ->with(array_merge([
                    'user:id,first_name,last_name,email',
                    'items.drTier',
                    'items.placements',
                    'billing',
                    'orderCoupons.coupon',
                ], $shared_invoice_withs))
                ->get()
                ->each(function ($o) use (&$models_by_id) { $models_by_id[$o->id] = $o; });
        }

        $nc_ids = $ids_by_type['new_content'] ?? [];
        if (! empty($nc_ids)) {
            NewContentOrder::whereIn('id', $nc_ids)
                ->with(array_merge([
                    'user:id,first_name,last_name,email',
                    'items.tier',
                    'billing',
                    'orderCoupons.coupon',
                ], $shared_invoice_withs))
                ->get()
                ->each(function ($o) use (&$models_by_id) { $models_by_id[$o->id] = $o; });
        }

        $co_ids = $ids_by_type['content_optimization'] ?? [];
        if (! empty($co_ids)) {
            ContentOptimizationOrder::whereIn('id', $co_ids)
                ->with(array_merge([
                    'user:id,first_name,last_name,email',
                    'items.tier',
                    'billing',
                    'orderCoupons.coupon',
                ], $shared_invoice_withs))
                ->get()
                ->each(function ($o) use (&$models_by_id) { $models_by_id[$o->id] = $o; });
        }

        $cb_ids = $ids_by_type['content_brief'] ?? [];
        if (! empty($cb_ids)) {
            ContentBriefOrder::whereIn('id', $cb_ids)
                ->with(array_merge([
                    'user:id,first_name,last_name,email',
                    'items.tier',
                    'billing',
                    'orderCoupons.coupon',
                ], $shared_invoice_withs))
                ->get()
                ->each(function ($o) use (&$models_by_id) { $models_by_id[$o->id] = $o; });
        }

        return $models_by_id;
    }

    private function formatOrderDetail(mixed $order, string $product_type): array
    {
        $subtotal_before_discount = (float) ($order->subtotal_before_discount ?? $order->items->sum('subtotal'));

        $user    = $order->user;
        $billing = $order->billing;
        $invoice = $order->invoice;

        return [
            'id'                       => $order->id,
            'user_id'                  => $order->user_id,
            'order_title'              => $order->order_title,
            'order_notes'              => $order->order_notes,
            'subtotal_before_discount' => $subtotal_before_discount,
            'total_amount'             => (float) $order->total_amount,
            'status'                   => $order->status,
            'payment_intent_id'        => $order->payment_intent_id,
            'session_id'               => $order->session_id,
            'session_title'            => $order->session_title,
            'product_type'             => $product_type,
            'items_count'              => $order->items->count(),
            'created_at'               => $order->created_at,
            'updated_at'               => $order->updated_at,
            'user' => $user ? [
                'id'         => $user->id,
                'first_name' => $user->first_name,
                'last_name'  => $user->last_name,
                'email'      => $user->email,
            ] : null,
            'items'   => $this->formatItems($order->items, $product_type),
            'billing' => $billing ? [
                'company'     => $billing->company,
                'address'     => $billing->address,
                'city'        => $billing->city,
                'state'       => $billing->state,
                'country'     => $billing->country,
                'postal_code' => $billing->postal_code,
            ] : null,
            'invoice' => $invoice ? $this->formatInvoice($invoice, $order) : null,
            'coupons' => $this->buildCoupons($order),
        ];
    }

    private function formatItems($items, string $product_type): array
    {
        return $items->map(function ($item) use ($product_type) {
            if ($product_type === 'link_building') {
                return [
                    'id'         => $item->id,
                    'dr_tier_id' => $item->dr_tier_id,
                    'quantity'   => $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'subtotal'   => (float) $item->subtotal,
                    'item_name'  => null,
                    'dr_tier'    => $item->drTier ? [
                        'id'             => $item->drTier->id,
                        'label'          => $item->drTier->label,
                        'traffic_range'  => $item->drTier->traffic_range,
                        'word_count'     => $item->drTier->word_count,
                        'price_per_link' => (float) $item->drTier->price_per_link,
                    ] : null,
                    'placements' => $item->placements->map(fn ($p) => [
                        'id'           => $p->id,
                        'row_index'    => $p->row_index,
                        'keyword'      => $p->keyword,
                        'landing_page' => $p->landing_page,
                        'exact_match'  => $p->exact_match,
                    ])->values()->all(),
                ];
            }

            $label_prefix = match ($product_type) {
                'new_content'          => 'New Content',
                'content_optimization' => 'Content Optimization',
                'content_brief'        => 'Content Brief',
                default                => 'Service',
            };

            $tier_label = $item->tier?->label;
            $item_name  = $tier_label ? "{$label_prefix} – {$tier_label}" : $label_prefix;

            return [
                'id'         => $item->id,
                'dr_tier_id' => null,
                'quantity'   => $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'subtotal'   => (float) $item->subtotal,
                'item_name'  => $item_name,
                'dr_tier'    => null,
                'placements' => [],
            ];
        })->values()->all();
    }

    private function formatInvoice(Invoice $invoice, mixed $order): array
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
            'coupon_discounts' => $this->buildCouponsForInvoice($order),
        ];
    }

    private function buildCoupons(mixed $order): array
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

    private function buildCouponsForInvoice(mixed $order): array
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
                'code'            => $coupon->code,
                'name'            => $coupon->name,
                'discount_type'   => $coupon->discount_type,
                'discount_value'  => $coupon->discount_value,
                'discount_amount' => $oc->discount_amount,
            ];
        })->filter()->values()->all();
    }
}
