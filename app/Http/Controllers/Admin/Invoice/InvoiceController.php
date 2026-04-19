<?php

namespace App\Http\Controllers\Admin\Invoice;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Invoice\ListInvoicesRequest;
use App\Http\Requests\Admin\Invoice\StoreInvoiceRequest;
use App\Http\Requests\Admin\Invoice\UpdateInvoiceRequest;
use App\Models\Invoice;
use App\Models\InvoiceHistory;
use App\Models\InvoiceLineItem;
use App\Models\User;
use App\Notifications\InvoiceCreatedNotification;
use App\Notifications\InvoiceUpdatedNotification;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class InvoiceController extends Controller
{
    public function __construct(
        protected NotificationService $notificationService
    ) {}

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
                  ->orWhere('invoices.unique_id', 'like', "%{$search}%")
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
     * POST /api/admin/invoices
     */
    public function store(StoreInvoiceRequest $request): JsonResponse
    {
        $user = User::find($request->input('user_id'));

        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $admin        = Auth::user();
        $currency     = $request->input('currency_type', 'usd');
        $raw_items    = $request->input('line_items');

        $subtotal_amount  = 0.0;
        $discount_amount  = 0.0;
        $computed_items   = [];

        foreach ($raw_items as $item) {
            $price            = (float) $item['price'];
            $quantity         = (int) $item['quantity'];
            $discount_percent = (float) ($item['discount_percent'] ?? 0);
            $gross            = $price * $quantity;
            $discount         = round($gross * ($discount_percent / 100), 2);
            $item_total       = round($gross - $discount, 2);

            $subtotal_amount += $item_total;
            $discount_amount += $discount;

            $computed_items[] = [
                'item_name'        => $item['item_name'],
                'description'      => $item['description'] ?? null,
                'price'            => $price,
                'quantity'         => $quantity,
                'discount_percent' => $discount_percent,
                'item_total'       => $item_total,
            ];
        }

        $subtotal_amount = round($subtotal_amount, 2);
        $discount_amount = round($discount_amount, 2);
        $total_amount    = $subtotal_amount;

        $invoice = DB::transaction(function () use (
            $user, $admin, $request, $currency,
            $subtotal_amount, $discount_amount, $total_amount, $computed_items
        ) {
            $unique_id      = strtoupper(bin2hex(random_bytes(4)));
            $invoice_number = 'BSM-' . str_pad(Invoice::count() + 1, 4, '0', STR_PAD_LEFT);

            $invoice = Invoice::create([
                'unique_id'       => $unique_id,
                'invoice_number'  => $invoice_number,
                'user_id'         => $user->id,
                'order_id'        => null,
                'status'          => 'void',
                'payment_method'  => 'Account Balance',
                'currency_type'   => $currency,
                'subtotal_amount' => $subtotal_amount,
                'discount_amount' => $discount_amount,
                'total_amount'    => $total_amount,
                'credit_amount'   => 0.0,
                'notes'           => $request->input('notes'),
                'date_issued'     => now(),
                'date_due'        => $request->input('date_due'),
                'date_paid'       => null,
            ]);

            foreach ($computed_items as $item) {
                $invoice->lineItems()->create($item);
            }

            $user->loadMissing(['billingAddress', 'organization']);
            $billing = $user->billingAddress;

            $invoice->billedTo()->create([
                'company_name'        => $billing?->company ?? $user->organization?->name,
                'company_description' => $user->job_title ?? null,
                'address_line_1'      => $billing?->address ?? null,
                'address_line_2'      => $billing?->address_line_2 ?? null,
                'state'               => $billing?->state_province ?? null,
                'country'             => $billing?->country ?? null,
            ]);

            $actor_name     = $admin->full_name ?? $admin->email;
            $actor_initials = $this->buildInitials($actor_name);

            InvoiceHistory::create([
                'invoice_id'     => $invoice->id,
                'event'          => "Invoice {$unique_id} created.",
                'description'    => 'Invoice generated manually by admin.',
                'actor_id'       => $admin->id,
                'actor_name'     => $actor_name,
                'actor_initials' => $actor_initials,
                'actor_type'     => 'admin',
            ]);

            return $invoice->load(['lineItems', 'billedTo', 'user']);
        });

        if ($request->boolean('send_client_notification')) {
            $invoice->user->notify(new InvoiceCreatedNotification($invoice, $invoice->user));

            InvoiceHistory::create([
                'invoice_id'     => $invoice->id,
                'event'          => 'Email notification sent to client.',
                'description'    => null,
                'actor_id'       => null,
                'actor_name'     => 'System',
                'actor_initials' => 'S',
                'actor_type'     => 'system',
            ]);
        }

        if ($request->boolean('send_admin_notification')) {
            User::whereHas('roles', fn ($q) => $q->whereIn('name', ['super_admin', 'admin']))
                ->each(function (User $admin_user) use ($invoice) {
                    $this->notificationService->createNotification(
                        user: $admin_user,
                        type: 'invoice',
                        message: "Invoice {$invoice->invoice_number} has been created for {$invoice->user->full_name}.",
                        extra: [
                            'link' => '/admin/invoices/' . $invoice->unique_id,
                        ],
                    );
                });
        }

        return response()->json($this->formatInvoice($invoice->load(['order.orderCoupons.coupon'])), 201);
    }

    /**
     * GET /api/admin/invoices/{invoice_id}
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

    /**
     * PATCH /api/admin/invoices/{invoice_id}
     */
    public function update(UpdateInvoiceRequest $request, string $invoice_id): JsonResponse
    {
        $invoice = Invoice::where('unique_id', $invoice_id)
            ->with(['user', 'lineItems', 'billedTo', 'order.orderCoupons.coupon'])
            ->first();

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

        $admin        = Auth::user();
        $changed      = [];

        $invoice = DB::transaction(function () use ($request, $invoice, $admin, &$changed) {
            if ($request->has('date_due')) {
                $invoice->date_due = $request->input('date_due');
                $changed[]         = 'due date';
            }

            if ($request->has('notes')) {
                $invoice->notes = $request->input('notes');
                $changed[]      = 'notes';
            }

            if ($request->has('line_items') && is_array($request->input('line_items'))) {
                $raw_items       = $request->input('line_items');
                $subtotal_amount = 0.0;
                $discount_amount = 0.0;
                $computed_items  = [];

                foreach ($raw_items as $item) {
                    $price            = (float) $item['price'];
                    $quantity         = (int) $item['quantity'];
                    $discount_percent = (float) ($item['discount_percent'] ?? 0);
                    $gross            = $price * $quantity;
                    $discount         = round($gross * ($discount_percent / 100), 2);
                    $item_total       = round($gross - $discount, 2);

                    $subtotal_amount += $item_total;
                    $discount_amount += $discount;

                    $computed_items[] = [
                        'item_name'        => $item['item_name'],
                        'description'      => $item['description'] ?? null,
                        'price'            => $price,
                        'quantity'         => $quantity,
                        'discount_percent' => $discount_percent,
                        'item_total'       => $item_total,
                    ];
                }

                $subtotal_amount = round($subtotal_amount, 2);
                $discount_amount = round($discount_amount, 2);

                InvoiceLineItem::where('invoice_id', $invoice->id)->delete();

                foreach ($computed_items as $item) {
                    $invoice->lineItems()->create($item);
                }

                $invoice->subtotal_amount = $subtotal_amount;
                $invoice->discount_amount = $discount_amount;
                $invoice->total_amount    = $subtotal_amount;
                $changed[]                = 'line items';
            }

            $invoice->save();

            $actor_name     = $admin->full_name ?? $admin->email;
            $actor_initials = $this->buildInitials($actor_name);
            $change_summary = count($changed) > 0
                ? implode(', ', $changed) . ' modified'
                : 'no fields changed';

            InvoiceHistory::create([
                'invoice_id'     => $invoice->id,
                'event'          => 'invoice_updated',
                'description'    => "Invoice updated by admin: {$change_summary}.",
                'actor_id'       => $admin->id,
                'actor_name'     => $actor_name,
                'actor_initials' => $actor_initials,
                'actor_type'     => 'admin',
            ]);

            return $invoice->load(['user', 'lineItems', 'billedTo', 'order.orderCoupons.coupon']);
        });

        if ($request->boolean('send_update_notification')) {
            $invoice->user->notify(new InvoiceUpdatedNotification($invoice, $invoice->user));

            InvoiceHistory::create([
                'invoice_id'     => $invoice->id,
                'event'          => 'Email update notification sent to client.',
                'description'    => null,
                'actor_id'       => null,
                'actor_name'     => 'System',
                'actor_initials' => 'S',
                'actor_type'     => 'system',
            ]);
        }

        return response()->json($this->formatInvoice($invoice));
    }

    /**
     * GET /api/admin/invoices/{invoice_id}/history
     */
    public function history(string $unique_id): JsonResponse
    {
        $invoice = Invoice::where('unique_id', $unique_id)->first();

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

        $entries = InvoiceHistory::where('invoice_id', $invoice->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (InvoiceHistory $entry) => [
                'id'              => $entry->id,
                'event'           => $entry->event,
                'description'     => $entry->description,
                'actor_name'      => $entry->actor_name,
                'actor_initials'  => $entry->actor_initials,
                'actor_type'      => $entry->actor_type,
                'created_at'      => $entry->created_at?->toIso8601String(),
            ])
            ->values();

        return response()->json($entries);
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
            'notes'           => $invoice->notes,
            'date_issued'     => $invoice->date_issued?->toIso8601String(),
            'date_due'        => $invoice->date_due?->toIso8601String(),
            'date_paid'       => $invoice->date_paid?->toIso8601String(),
            'created_at'      => $invoice->created_at?->toIso8601String(),
            'updated_at'      => $invoice->updated_at?->toIso8601String(),
            'user' => [
                'id'         => $invoice->user->id,
                'first_name' => $invoice->user->first_name,
                'last_name'  => $invoice->user->last_name,
                'email'      => $invoice->user->email,
            ],
            'line_items' => $invoice->lineItems->map(fn ($item) => [
                'id'               => $item->id,
                'item_name'        => $item->item_name,
                'description'      => $item->description,
                'price'            => $item->price,
                'quantity'         => $item->quantity,
                'discount_percent' => $item->discount_percent,
                'item_total'       => $item->item_total,
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

    private function buildInitials(string $name): string
    {
        $parts = array_filter(explode(' ', trim($name)));

        if (count($parts) >= 2) {
            return strtoupper(mb_substr($parts[0], 0, 1) . mb_substr(end($parts), 0, 1));
        }

        return strtoupper(mb_substr($name, 0, 1));
    }
}
