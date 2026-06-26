<?php

namespace App\Http\Controllers\Admin\Invoice;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Invoice\ListInvoicesRequest;
use App\Http\Requests\Admin\Invoice\PartialRefundInvoiceRequest;
use App\Http\Requests\Admin\Invoice\RefundInvoiceRequest;
use App\Http\Requests\Admin\Invoice\StoreInvoiceRequest;
use App\Http\Requests\Admin\Invoice\UpdateInvoiceBillingRequest;
use App\Http\Requests\Admin\Invoice\UpdateInvoiceRequest;
use App\Jobs\SendAdminInvoiceRefundedNotificationJob;
use App\Jobs\SendClientInvoiceRefundedNotificationJob;
use App\Models\Invoice;
use App\Models\InvoiceHistory;
use App\Models\InvoiceLineItem;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\InvoiceCreatedNotification;
use App\Notifications\InvoiceReminderNotification;
use App\Notifications\InvoiceUpdatedNotification;
use App\Services\NotificationService;
use App\Services\StripeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class InvoiceController extends Controller
{
    private const PRODUCT_LABELS = [
        'link_building'        => 'Link Building',
        'new_content'          => 'New Content',
        'content_optimization' => 'Content Optimization',
        'content_brief'        => 'Content Brief',
    ];

    public function __construct(
        protected NotificationService $notificationService,
        protected StripeService $stripeService
    ) {}

    /**
     * GET /api/admin/invoices
     */
    public function index(ListInvoicesRequest $request): JsonResponse
    {
        $query = Invoice::with(['user', 'lineItems', 'billedTo', 'couponDiscounts'])
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

        $data = $invoices->map(fn (Invoice $invoice) => $this->formatInvoice($invoice, false))->values();

        return response()->json([
            'data'         => $data,
            'current_page' => $invoices->currentPage(),
            'last_page'    => $invoices->lastPage(),
            'per_page'     => $invoices->perPage(),
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

        $admin     = Auth::user();
        $currency  = $request->input('currency_type', 'usd');
        $raw_items = $request->input('line_items');

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
                'session_id'      => null,
                'session_title'   => null,
                'status'          => 'unpaid',
                'payment_method'  => 'Account Balance',
                'currency_type'   => $currency,
                'subtotal_amount' => $subtotal_amount,
                'discount_amount' => $discount_amount,
                'discount_type'   => $discount_amount > 0 ? 'line_item' : null,
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
                'event'          => 'invoice created',
                'description'    => 'Invoice generated manually by admin.',
                'actor_id'       => $admin->id,
                'actor_name'     => $actor_name,
                'actor_initials' => $actor_initials,
                'actor_type'     => 'admin',
            ]);

            return $invoice->load(['lineItems', 'billedTo', 'user', 'couponDiscounts']);
        });

        if ($request->boolean('send_client_notification')) {
            $invoice->user->notify(new InvoiceCreatedNotification($invoice, $invoice->user));

            InvoiceHistory::create([
                'invoice_id'     => $invoice->id,
                'event'          => 'email notification sent to client',
                'description'    => null,
                'actor_id'       => null,
                'actor_name'     => 'System',
                'actor_initials' => 'SY',
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
                            'link' => '/admin/invoices/' . $invoice->id,
                        ],
                    );
                });
        }

        return response()->json($this->formatInvoice($invoice), 201);
    }

    /**
     * GET /api/admin/invoices/{invoice_id}
     */
    public function show(string $invoice_id): JsonResponse
    {
        $invoice = Invoice::with(['user', 'lineItems', 'billedTo', 'couponDiscounts'])
            ->find($invoice_id);

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
        $invoice = Invoice::with(['user', 'lineItems', 'billedTo', 'couponDiscounts'])
            ->find($invoice_id);

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

        $admin   = Auth::user();
        $changed = [];

        $invoice = DB::transaction(function () use ($request, $invoice, $admin, &$changed) {
            if ($request->has('user_id')) {
                $new_user = User::find($request->input('user_id'));
                if ($new_user && $new_user->id !== $invoice->user_id) {
                    $invoice->user_id = $new_user->id;
                    $changed[]        = 'client';
                }
            }

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
                $invoice->discount_type   = $discount_amount > 0 ? 'line_item' : null;
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
                'event'          => 'invoice updated',
                'description'    => "Invoice updated by admin: {$change_summary}.",
                'actor_id'       => $admin->id,
                'actor_name'     => $actor_name,
                'actor_initials' => $actor_initials,
                'actor_type'     => 'admin',
            ]);

            return $invoice->load(['user', 'lineItems', 'billedTo', 'couponDiscounts']);
        });

        if ($request->boolean('send_update_notification')) {
            $invoice->user->notify(new InvoiceUpdatedNotification($invoice, $invoice->user));

            InvoiceHistory::create([
                'invoice_id'     => $invoice->id,
                'event'          => 'email update notification sent to client',
                'description'    => null,
                'actor_id'       => null,
                'actor_name'     => 'System',
                'actor_initials' => 'SY',
                'actor_type'     => 'system',
            ]);
        }

        return response()->json($this->formatInvoice($invoice));
    }

    /**
     * PATCH /api/admin/invoices/{invoice_id}/billing
     */
    public function updateBilling(UpdateInvoiceBillingRequest $request, string $invoice_id): JsonResponse
    {
        $invoice = Invoice::with(['user', 'lineItems', 'billedTo', 'couponDiscounts'])
            ->find($invoice_id);

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

        $billing_fields = ['company_name', 'company_description', 'address_line_1', 'address_line_2', 'state', 'country'];
        $billing_data   = [];

        foreach ($billing_fields as $field) {
            if ($request->has($field)) {
                $billing_data[$field] = $request->input($field);
            }
        }

        if ($invoice->billedTo) {
            $invoice->billedTo->update($billing_data);
        } else {
            $invoice->billedTo()->create($billing_data);
        }

        $admin          = Auth::user();
        $actor_name     = $admin->full_name ?? $admin->email;
        $actor_initials = $this->buildInitials($actor_name);

        InvoiceHistory::create([
            'invoice_id'     => $invoice->id,
            'event'          => 'billing details updated',
            'description'    => 'Billing details updated by admin.',
            'actor_id'       => $admin->id,
            'actor_name'     => $actor_name,
            'actor_initials' => $actor_initials,
            'actor_type'     => 'admin',
        ]);

        return response()->json($this->formatInvoice(
            $invoice->fresh(['user', 'lineItems', 'billedTo', 'couponDiscounts'])
        ));
    }

    /**
     * POST /api/admin/invoices/{invoice_id}/mark-paid
     */
    public function markPaid(string $invoice_id): JsonResponse
    {
        $invoice = Invoice::with(['user', 'lineItems', 'billedTo', 'couponDiscounts'])
            ->find($invoice_id);

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

        if ($invoice->status === 'paid') {
            return response()->json(['message' => 'Invoice is already marked as paid.'], 422);
        }

        $admin          = Auth::user();
        $actor_name     = $admin->full_name ?? $admin->email;
        $actor_initials = $this->buildInitials($actor_name);

        $invoice->status    = 'paid';
        $invoice->date_paid = now();
        $invoice->save();

        InvoiceHistory::create([
            'invoice_id'     => $invoice->id,
            'event'          => 'marked invoice as paid',
            'description'    => 'Invoice manually marked as paid by admin.',
            'actor_id'       => $admin->id,
            'actor_name'     => $actor_name,
            'actor_initials' => $actor_initials,
            'actor_type'     => 'admin',
        ]);

        $this->recordInvoiceTransaction($invoice);

        return response()->json($this->formatInvoice(
            $invoice->fresh(['user', 'lineItems', 'billedTo', 'couponDiscounts'])
        ));
    }

    /**
     * POST /api/admin/invoices/{invoice_id}/mark-unpaid
     */
    public function markUnpaid(string $invoice_id): JsonResponse
    {
        $invoice = Invoice::with(['user', 'lineItems', 'billedTo', 'couponDiscounts'])
            ->find($invoice_id);

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

        if ($invoice->status === 'unpaid') {
            return response()->json(['message' => 'Invoice is already marked as unpaid.'], 422);
        }

        $admin          = Auth::user();
        $actor_name     = $admin->full_name ?? $admin->email;
        $actor_initials = $this->buildInitials($actor_name);

        $invoice->status    = 'unpaid';
        $invoice->date_paid = null;
        $invoice->save();

        InvoiceHistory::create([
            'invoice_id'     => $invoice->id,
            'event'          => 'marked invoice as unpaid',
            'description'    => 'Invoice manually marked as unpaid by admin.',
            'actor_id'       => $admin->id,
            'actor_name'     => $actor_name,
            'actor_initials' => $actor_initials,
            'actor_type'     => 'admin',
        ]);

        return response()->json($this->formatInvoice(
            $invoice->fresh(['user', 'lineItems', 'billedTo', 'couponDiscounts'])
        ));
    }

    /**
     * POST /api/admin/invoices/{invoice_id}/mark-overdue
     */
    public function markOverdue(string $invoice_id): JsonResponse
    {
        $invoice = Invoice::with(['user', 'lineItems', 'billedTo', 'couponDiscounts'])
            ->find($invoice_id);

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

        if ($invoice->status === 'overdue') {
            return response()->json(['message' => 'Invoice is already marked as overdue.'], 422);
        }

        $admin          = Auth::user();
        $actor_name     = $admin->full_name ?? $admin->email;
        $actor_initials = $this->buildInitials($actor_name);

        $invoice->status = 'overdue';
        $invoice->save();

        InvoiceHistory::create([
            'invoice_id'     => $invoice->id,
            'event'          => 'marked invoice as overdue',
            'description'    => 'Invoice manually marked as overdue by admin.',
            'actor_id'       => $admin->id,
            'actor_name'     => $actor_name,
            'actor_initials' => $actor_initials,
            'actor_type'     => 'admin',
        ]);

        return response()->json($this->formatInvoice(
            $invoice->fresh(['user', 'lineItems', 'billedTo', 'couponDiscounts'])
        ));
    }

    /**
     * POST /api/admin/invoices/{invoice_id}/refund
     */
    public function refundInvoice(RefundInvoiceRequest $request, string $invoice_id): JsonResponse
    {
        $invoice = Invoice::with(['user', 'lineItems', 'billedTo', 'couponDiscounts'])
            ->find($invoice_id);

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

        if ($invoice->status === 'refund') {
            return response()->json(['message' => 'Invoice is already marked as refunded.'], 422);
        }

        if ($invoice->status !== 'paid') {
            return response()->json([
                'message' => 'Only paid invoices can be refunded. The customer must complete payment first.',
            ], 422);
        }

        // Allow admin to supply a payment_intent_id for older invoices that lack one
        $request_pi = trim((string) $request->input('payment_intent_id', ''));
        if ($request_pi && ! $invoice->payment_intent_id) {
            $invoice->payment_intent_id = $request_pi;
            $invoice->save();
        }

        // Determine credit vs card breakdown
        $credit_portion = (float) ($invoice->credit_amount ?? 0);
        $card_portion   = max(0, round($invoice->total_amount - $credit_portion, 2));

        // Restore account credits to the client's balance
        if ($credit_portion > 0) {
            $invoice->user->increment('credit_balance', $credit_portion);
        }

        // Process Stripe refund only for the card portion
        $stripe_refund_id = null;
        if ($card_portion > 0 && $invoice->payment_intent_id) {
            $amount_cents = (int) round($card_portion * 100);
            $result = $this->stripeService->refundPaymentIntent(
                $invoice->payment_intent_id,
                'requested_by_customer',
                $amount_cents
            );

            if (! $result['success']) {
                // Roll back the credit restoration on Stripe failure
                if ($credit_portion > 0) {
                    $invoice->user->decrement('credit_balance', $credit_portion);
                }
                return response()->json([
                    'message' => 'Stripe refund failed: ' . ($result['message'] ?? 'Unknown error.'),
                ], 422);
            }

            $stripe_refund_id = $result['refund_id'];
        }

        $admin           = Auth::user();
        $actor_name      = $admin->full_name ?? $admin->email;
        $actor_initials  = $this->buildInitials($actor_name);
        $formatted_total = number_format((float) $invoice->total_amount, 2);

        $invoice->status        = 'refund';
        $invoice->refund_amount = $invoice->total_amount;
        $invoice->refunded_at   = now();
        $invoice->save();

        // Build audit description based on payment breakdown
        $parts = [];
        if ($credit_portion > 0) {
            $parts[] = '$' . number_format($credit_portion, 2) . ' returned to client account balance';
        }
        if ($card_portion > 0) {
            $card_via = $stripe_refund_id
                ? '$' . number_format($card_portion, 2) . " refunded via Stripe (refund ID: {$stripe_refund_id})"
                : '$' . number_format($card_portion, 2) . ' recorded manually (no Stripe payment on file)';
            $parts[] = $card_via;
        }
        $description = 'Full refund of $' . $formatted_total . ' processed: ' . implode('; ', $parts) . '.';

        InvoiceHistory::create([
            'invoice_id'     => $invoice->id,
            'event'          => 'invoice refunded',
            'description'    => $description,
            'actor_id'       => $admin->id,
            'actor_name'     => $actor_name,
            'actor_initials' => $actor_initials,
            'actor_type'     => 'admin',
        ]);

        // Record credit refund transaction
        if ($credit_portion > 0) {
            Transaction::create([
                'user_id'    => $invoice->user_id,
                'type'       => 'refund',
                'status'     => 'success',
                'amount'     => $credit_portion,
                'payment_method' => 'account_credits',
                'invoice_id' => (string) $invoice->id,
                'description' => 'Credit refund of $' . number_format($credit_portion, 2) . " returned to account balance for invoice {$invoice->invoice_number}.",
            ]);
        }

        // Record card refund transaction
        if ($card_portion > 0) {
            Transaction::create([
                'user_id'           => $invoice->user_id,
                'type'              => 'refund',
                'status'            => 'success',
                'amount'            => $card_portion,
                'payment_method'    => 'credit_card',
                'payment_intent_id' => $invoice->payment_intent_id,
                'invoice_id'        => (string) $invoice->id,
                'description'       => 'Card refund of $' . number_format($card_portion, 2) . " issued for invoice {$invoice->invoice_number}." . ($stripe_refund_id ? " Stripe: {$stripe_refund_id}." : ' Manual — no Stripe payment on file.'),
            ]);
        }

        $this->dispatchRefundNotifications(
            invoice: $invoice,
            refund_amount: (float) $invoice->total_amount,
            total_refunded: (float) $invoice->refund_amount,
            credit_refund: $credit_portion,
            card_refund: $card_portion,
            is_full_refund: true,
            stripe_refund_id: $stripe_refund_id,
            actor_name: $actor_name,
            send_client_notification: $request->boolean('send_client_notification', true),
        );

        return response()->json($this->formatInvoice(
            $invoice->fresh(['user', 'lineItems', 'billedTo', 'couponDiscounts'])
        ));
    }

    /**
     * POST /api/admin/invoices/{invoice_id}/partial-refund
     */
    public function partialRefundInvoice(PartialRefundInvoiceRequest $request, string $invoice_id): JsonResponse
    {
        $invoice = Invoice::with(['user', 'lineItems', 'billedTo', 'couponDiscounts'])
            ->find($invoice_id);

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

        // Partial refunds may be issued while the invoice is "paid" or already
        // "partial_refund". A "refund" status means the total has been fully
        // refunded, so it is intentionally excluded here.
        if (! in_array($invoice->status, ['paid', 'partial_refund'])) {
            $message = $invoice->status === 'refund'
                ? 'This invoice has already been fully refunded.'
                : 'Only paid or partially refunded invoices can be partially refunded. The customer must complete payment first.';

            return response()->json(['message' => $message], 422);
        }

        $refund_amount = (float) $request->input('refund_amount', 0);

        if ($refund_amount <= 0) {
            return response()->json(['message' => 'Refund amount must be greater than zero.'], 422);
        }

        $already_refunded   = (float) ($invoice->refund_amount ?? 0);
        $remaining_balance  = round($invoice->total_amount - $already_refunded, 2);
        $total_refunded     = round($already_refunded + $refund_amount, 2);

        if ($remaining_balance <= 0) {
            return response()->json([
                'message' => 'This invoice has already been fully refunded. No remaining balance to refund.',
            ], 422);
        }

        if ($total_refunded > $invoice->total_amount) {
            return response()->json([
                'message' => "Refund amount exceeds the remaining refundable balance of \${$remaining_balance}.",
            ], 422);
        }

        // Allow admin to supply a payment_intent_id for older invoices that lack one
        $request_pi = trim((string) $request->input('payment_intent_id', ''));
        if ($request_pi && ! $invoice->payment_intent_id) {
            $invoice->payment_intent_id = $request_pi;
            $invoice->save();
        }

        // Determine how much of the original payment was made with account credits vs card
        $credit_portion_total = (float) ($invoice->credit_amount ?? 0);

        // Compute how many credits have already been refunded for this invoice
        $already_credit_refunded = (float) Transaction::where('invoice_id', $invoice->id)
            ->where('payment_method', 'account_credits')
            ->whereIn('type', ['refund', 'partial_refund'])
            ->where('status', 'success')
            ->sum('amount');

        $available_credit_refund = max(0, round($credit_portion_total - $already_credit_refunded, 2));

        // Apply credits first, then card for the remainder
        $credit_refund = round(min($refund_amount, $available_credit_refund), 2);
        $card_refund   = round($refund_amount - $credit_refund, 2);

        // Restore account credits to the client's balance
        if ($credit_refund > 0) {
            $invoice->user->increment('credit_balance', $credit_refund);
        }

        // Process Stripe partial refund for the card portion
        $stripe_refund_id = null;
        if ($card_refund > 0 && $invoice->payment_intent_id) {
            $amount_cents = (int) round($card_refund * 100);
            $result = $this->stripeService->refundPaymentIntent(
                $invoice->payment_intent_id,
                'requested_by_customer',
                $amount_cents
            );

            if (! $result['success']) {
                // Roll back the credit restoration on Stripe failure
                if ($credit_refund > 0) {
                    $invoice->user->decrement('credit_balance', $credit_refund);
                }
                return response()->json([
                    'message' => 'Stripe refund failed: ' . ($result['message'] ?? 'Unknown error.'),
                ], 422);
            }

            $stripe_refund_id = $result['refund_id'];
        }

        $admin          = Auth::user();
        $actor_name     = $admin->full_name ?? $admin->email;
        $actor_initials = $this->buildInitials($actor_name);

        $invoice->refund_amount = $total_refunded;
        $invoice->refunded_at   = now();

        // Fully refunded invoices become 'refund'; anything less is a 'partial_refund'
        // so the status mirrors the Stripe-aligned transaction type in the table.
        // When the cumulative refund reaches the invoice total the status flips
        // to 'refund' automatically, which disables any further partial refunds.
        $is_now_fully_refunded = $total_refunded >= $invoice->total_amount;
        $invoice->status       = $is_now_fully_refunded ? 'refund' : 'partial_refund';

        $invoice->save();

        // Build audit description based on payment breakdown
        $parts = [];
        if ($credit_refund > 0) {
            $parts[] = '$' . number_format($credit_refund, 2) . ' returned to client account balance';
        }
        if ($card_refund > 0) {
            $card_via = $stripe_refund_id
                ? '$' . number_format($card_refund, 2) . " refunded via Stripe (refund ID: {$stripe_refund_id})"
                : '$' . number_format($card_refund, 2) . ' recorded manually (no Stripe payment on file)';
            $parts[] = $card_via;
        }

        $description = 'Admin issued a partial refund of $' . number_format($refund_amount, 2)
            . ': ' . implode('; ', $parts)
            . '. Total refunded: $' . number_format($total_refunded, 2) . '.';

        if ($is_now_fully_refunded) {
            $description .= ' The invoice is now fully refunded and its status has been updated to Refund.';
        }

        InvoiceHistory::create([
            'invoice_id'     => $invoice->id,
            'event'          => $is_now_fully_refunded ? 'invoice fully refunded' : 'partial refund issued',
            'description'    => $description,
            'actor_id'       => $admin->id,
            'actor_name'     => $actor_name,
            'actor_initials' => $actor_initials,
            'actor_type'     => 'admin',
        ]);

        // Record credit refund transaction
        if ($credit_refund > 0) {
            Transaction::create([
                'user_id'    => $invoice->user_id,
                'type'       => 'partial_refund',
                'status'     => 'success',
                'amount'     => $credit_refund,
                'payment_method' => 'account_credits',
                'invoice_id' => (string) $invoice->id,
                'description' => 'Partial credit refund of $' . number_format($credit_refund, 2) . " returned to account balance for invoice {$invoice->invoice_number}.",
            ]);
        }

        // Record card refund transaction
        if ($card_refund > 0) {
            Transaction::create([
                'user_id'           => $invoice->user_id,
                'type'              => 'partial_refund',
                'status'            => 'success',
                'amount'            => $card_refund,
                'payment_method'    => 'credit_card',
                'payment_intent_id' => $invoice->payment_intent_id,
                'invoice_id'        => (string) $invoice->id,
                'description'       => 'Partial card refund of $' . number_format($card_refund, 2) . " issued for invoice {$invoice->invoice_number}." . ($stripe_refund_id ? " Stripe: {$stripe_refund_id}." : ' Manual — no Stripe payment on file.'),
            ]);
        }

        $this->dispatchRefundNotifications(
            invoice: $invoice,
            refund_amount: $refund_amount,
            total_refunded: $total_refunded,
            credit_refund: $credit_refund,
            card_refund: $card_refund,
            is_full_refund: $is_now_fully_refunded,
            stripe_refund_id: $stripe_refund_id,
            actor_name: $actor_name,
            send_client_notification: $request->boolean('send_client_notification', true),
        );

        return response()->json($this->formatInvoice(
            $invoice->fresh(['user', 'lineItems', 'billedTo', 'couponDiscounts'])
        ));
    }

    /**
     * PATCH /api/admin/invoices/{invoice_id}/payment-intent
     */
    public function setPaymentIntent(Request $request, string $invoice_id): JsonResponse
    {
        $request->validate([
            'payment_intent_id' => ['required', 'string', 'max:255'],
        ]);

        $invoice = Invoice::with(['user', 'lineItems', 'billedTo', 'couponDiscounts'])
            ->find($invoice_id);

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

        $payment_intent_id = trim($request->input('payment_intent_id'));
        $old_pi            = $invoice->payment_intent_id;

        $admin          = Auth::user();
        $actor_name     = $admin->full_name ?? $admin->email;
        $actor_initials = $this->buildInitials($actor_name);

        $invoice->payment_intent_id = $payment_intent_id;
        $invoice->save();

        $description = $old_pi
            ? "Stripe PaymentIntent ID updated from {$old_pi} to {$payment_intent_id}."
            : "Stripe PaymentIntent ID set to {$payment_intent_id}.";

        InvoiceHistory::create([
            'invoice_id'     => $invoice->id,
            'event'          => 'stripe id updated',
            'description'    => $description,
            'actor_id'       => $admin->id,
            'actor_name'     => $actor_name,
            'actor_initials' => $actor_initials,
            'actor_type'     => 'admin',
        ]);

        return response()->json($this->formatInvoice(
            $invoice->fresh(['user', 'lineItems', 'billedTo', 'couponDiscounts'])
        ));
    }

    /**
     * POST /api/admin/invoices/{invoice_id}/void
     */
    public function voidInvoice(string $invoice_id): JsonResponse
    {
        $invoice = Invoice::with(['user', 'lineItems', 'billedTo', 'couponDiscounts'])
            ->find($invoice_id);

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

        if ($invoice->status === 'void') {
            return response()->json(['message' => 'Invoice is already voided.'], 422);
        }

        $admin          = Auth::user();
        $actor_name     = $admin->full_name ?? $admin->email;
        $actor_initials = $this->buildInitials($actor_name);

        $invoice->status = 'void';
        $invoice->save();

        InvoiceHistory::create([
            'invoice_id'     => $invoice->id,
            'event'          => 'invoice voided',
            'description'    => 'Invoice voided by admin.',
            'actor_id'       => $admin->id,
            'actor_name'     => $actor_name,
            'actor_initials' => $actor_initials,
            'actor_type'     => 'admin',
        ]);

        return response()->json($this->formatInvoice(
            $invoice->fresh(['user', 'lineItems', 'billedTo', 'couponDiscounts'])
        ));
    }

    /**
     * POST /api/admin/invoices/{invoice_id}/duplicate
     */
    public function duplicate(string $invoice_id): JsonResponse
    {
        $original = Invoice::with(['user', 'lineItems', 'billedTo', 'couponDiscounts'])
            ->find($invoice_id);

        if (! $original) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

        $admin = Auth::user();

        $new_invoice = DB::transaction(function () use ($original, $admin) {
            $unique_id      = strtoupper(bin2hex(random_bytes(4)));
            $invoice_number = 'BSM-' . str_pad(Invoice::count() + 1, 4, '0', STR_PAD_LEFT);

            $new_invoice = Invoice::create([
                'unique_id'       => $unique_id,
                'invoice_number'  => $invoice_number,
                'user_id'         => $original->user_id,
                'order_id'        => null,
                'session_id'      => null,
                'session_title'   => null,
                'status'          => 'unpaid',
                'payment_method'  => $original->payment_method,
                'currency_type'   => $original->currency_type,
                'subtotal_amount' => $original->subtotal_amount,
                'discount_amount' => $original->discount_amount,
                'discount_type'   => $original->discount_type,
                'total_amount'    => $original->total_amount,
                'credit_amount'   => 0.0,
                'notes'           => $original->notes,
                'date_issued'     => now(),
                'date_due'        => $original->date_due,
                'date_paid'       => null,
            ]);

            foreach ($original->lineItems as $item) {
                $new_invoice->lineItems()->create([
                    'item_name'        => $item->item_name,
                    'product_type'     => $item->product_type,
                    'description'      => $item->description,
                    'price'            => $item->price,
                    'quantity'         => $item->quantity,
                    'discount_percent' => $item->discount_percent,
                    'item_total'       => $item->item_total,
                ]);
            }

            if ($original->billedTo) {
                $new_invoice->billedTo()->create([
                    'company_name'        => $original->billedTo->company_name,
                    'company_description' => $original->billedTo->company_description,
                    'address_line_1'      => $original->billedTo->address_line_1,
                    'address_line_2'      => $original->billedTo->address_line_2,
                    'state'               => $original->billedTo->state,
                    'country'             => $original->billedTo->country,
                ]);
            }

            $actor_name     = $admin->full_name ?? $admin->email;
            $actor_initials = $this->buildInitials($actor_name);

            InvoiceHistory::create([
                'invoice_id'     => $new_invoice->id,
                'event'          => 'invoice duplicated',
                'description'    => "Duplicated from invoice {$original->unique_id}.",
                'actor_id'       => $admin->id,
                'actor_name'     => $actor_name,
                'actor_initials' => $actor_initials,
                'actor_type'     => 'admin',
            ]);

            return $new_invoice->load(['user', 'lineItems', 'billedTo', 'couponDiscounts']);
        });

        return response()->json($this->formatInvoice($new_invoice), 201);
    }

    /**
     * DELETE /api/admin/invoices/{invoice_id}
     */
    public function destroy(string $invoice_id): Response|JsonResponse
    {
        $invoice = Invoice::find($invoice_id);

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

        $invoice->delete();

        return response()->noContent();
    }

    /**
     * POST /api/admin/invoices/{invoice_id}/send-reminder
     */
    public function sendReminder(string $invoice_id): JsonResponse
    {
        $invoice = Invoice::with(['user', 'lineItems'])
            ->find($invoice_id);

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

        if ($invoice->status === 'paid') {
            return response()->json(['message' => 'Unable to send reminder. Invoice may already be paid.'], 422);
        }

        $invoice->user->notify(new InvoiceReminderNotification($invoice, $invoice->user));

        InvoiceHistory::create([
            'invoice_id'     => $invoice->id,
            'event'          => 'reminder sent',
            'description'    => 'Payment reminder email sent to client.',
            'actor_id'       => null,
            'actor_name'     => 'System',
            'actor_initials' => 'SY',
            'actor_type'     => 'system',
        ]);

        return response()->json(['message' => 'Reminder email sent successfully.']);
    }

    /**
     * GET /api/admin/invoices/{invoice_id}/history
     */
    public function history(string $invoice_id): JsonResponse
    {
        $invoice = Invoice::find($invoice_id);

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

        $entries = InvoiceHistory::where('invoice_id', $invoice->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (InvoiceHistory $entry) => [
                'id'             => $entry->id,
                'event'          => $entry->event,
                'description'    => $entry->description,
                'actor_name'     => $entry->actor_name,
                'actor_initials' => $entry->actor_initials,
                'actor_type'     => $entry->actor_type,
                'created_at'     => $entry->created_at?->toIso8601String(),
            ])
            ->values();

        return response()->json($entries);
    }

    /**
     * Formats a full AdminInvoice response object.
     *
     * @param bool $include_item_details  When false, invoice_products items arrays are empty (list view optimization).
     */
    private function formatInvoice(Invoice $invoice, bool $include_item_details = true): array
    {
        $billed_to     = $invoice->billedTo;
        $product_data  = $this->buildProductData($invoice, $include_item_details);

        return [
            'id'                 => $invoice->id,
            'unique_id'          => $invoice->unique_id,
            'invoice_number'     => $invoice->invoice_number,
            'user_id'            => $invoice->user_id,
            'order_id'           => $invoice->order_id,
            'session_id'         => $invoice->session_id,
            'session_title'      => $invoice->session_title,
            'status'             => $invoice->status,
            'payment_method'     => $invoice->payment_method,
            'payment_intent_id'  => $invoice->payment_intent_id,
            'has_stripe_payment' => ! empty($invoice->payment_intent_id),
            'currency_type'      => $invoice->currency_type,
            'subtotal_amount' => $invoice->subtotal_amount,
            'discount_amount' => $invoice->discount_amount,
            'discount_type'   => $invoice->discount_type,
            'total_amount'    => $invoice->total_amount,
            'credit_amount'   => $invoice->credit_amount,
            'refund_amount'   => $invoice->refund_amount,
            'notes'           => $invoice->notes,
            'date_issued'     => $invoice->date_issued?->toIso8601String(),
            'date_due'        => $invoice->date_due?->toIso8601String(),
            'date_paid'       => $invoice->date_paid?->toIso8601String(),
            'refunded_at'     => $invoice->refunded_at?->toIso8601String(),
            'created_at'      => $invoice->created_at?->toIso8601String(),
            'updated_at'      => $invoice->updated_at?->toIso8601String(),
            'user' => [
                'id'         => $invoice->user->id,
                'first_name' => $invoice->user->first_name,
                'last_name'  => $invoice->user->last_name,
                'email'      => $invoice->user->email,
            ],
            'billed_to' => $billed_to ? [
                'company_name'        => $billed_to->company_name,
                'company_description' => $billed_to->company_description,
                'address_line_1'      => $billed_to->address_line_1,
                'address_line_2'      => $billed_to->address_line_2,
                'state'               => $billed_to->state,
                'country'             => $billed_to->country,
            ] : null,
            'line_items' => $invoice->lineItems->map(fn ($item) => [
                'id'               => $item->id,
                'item_name'        => $item->item_name,
                'description'      => $item->description,
                'price'            => $item->price,
                'quantity'         => $item->quantity,
                'discount_percent' => $item->discount_percent,
                'item_total'       => $item->item_total,
            ])->values(),
            'product_type'     => $product_data['product_type'],
            'invoice_products' => $product_data['invoice_products'],
            'coupon_discounts' => $this->buildCouponDiscounts($invoice),
        ];
    }

    /**
     * Determines product_type and builds the invoice_products grouping from line items.
     *
     * Single product → product_type = "link_building", invoice_products = null
     * Multi-product  → product_type = null, invoice_products = [grouped array]
     * Manual invoice → product_type = null, invoice_products = null
     */
    private function buildProductData(Invoice $invoice, bool $include_items): array
    {
        $typed_items = $invoice->lineItems->filter(fn ($item) => ! empty($item->product_type));

        if ($typed_items->isEmpty()) {
            return ['product_type' => null, 'invoice_products' => null];
        }

        $distinct_types     = $typed_items->pluck('product_type')->unique();
        $distinct_order_ids = $typed_items->pluck('order_id')->filter()->unique();

        if ($distinct_types->count() === 1 && $distinct_order_ids->count() <= 1) {
            return [
                'product_type'     => $distinct_types->first(),
                'invoice_products' => null,
            ];
        }

        // Group by order_id when available, otherwise fall back to product_type
        $groups = $typed_items->groupBy(fn ($item) => $item->order_id ?? $item->product_type);

        $invoice_products = $groups->map(function ($items, $group_key) use ($include_items) {
            $product_type = $items->first()->product_type;
            $order_id     = $items->first()->order_id;
            $subtotal     = round($items->sum('item_total'), 2);
            $label        = self::PRODUCT_LABELS[$product_type]
                ?? ucwords(str_replace('_', ' ', (string) $product_type));

            return [
                'product_type' => $product_type,
                'order_id'     => $order_id,
                'label'        => $label,
                'subtotal'     => $subtotal,
                'items'        => $include_items
                    ? $items->map(fn ($item) => [
                        'id'         => $item->id,
                        'item_name'  => $item->item_name,
                        'price'      => $item->price,
                        'quantity'   => $item->quantity,
                        'item_total' => $item->item_total,
                    ])->values()->toArray()
                    : [],
            ];
        })->values()->toArray();

        return [
            'product_type'     => null,
            'invoice_products' => $invoice_products,
        ];
    }

    private function buildCouponDiscounts(Invoice $invoice): array
    {
        if (! $invoice->relationLoaded('couponDiscounts')) {
            return [];
        }

        return $invoice->couponDiscounts->map(fn ($cd) => [
            'code'            => $cd->code,
            'name'            => $cd->name,
            'discount_type'   => $cd->discount_type,
            'discount_value'  => $cd->discount_value,
            'discount_amount' => $cd->discount_amount,
        ])->values()->all();
    }

    private function buildInitials(string $name): string
    {
        $parts = array_filter(explode(' ', trim($name)));

        if (count($parts) >= 2) {
            return strtoupper(mb_substr($parts[0], 0, 1) . mb_substr(end($parts), 0, 1));
        }

        return strtoupper(mb_substr($name, 0, 2));
    }

    /**
     * Queues the refund notification emails after a refund / partial refund.
     *
     * The admin alert is dispatched on every refund so the team configured in
     * the Email Notification Settings is always kept in the loop. The
     * client-facing email is only queued when the admin left the "Notify client"
     * checkbox enabled. Both are delivered through queued Laravel jobs so the
     * HTTP response is never blocked on mail delivery.
     */
    private function dispatchRefundNotifications(
        Invoice $invoice,
        float $refund_amount,
        float $total_refunded,
        float $credit_refund,
        float $card_refund,
        bool $is_full_refund,
        ?string $stripe_refund_id,
        string $actor_name,
        bool $send_client_notification,
    ): void {
        // Always keep the admin team informed of money leaving the platform.
        SendAdminInvoiceRefundedNotificationJob::dispatch(
            invoice_id: (string) $invoice->id,
            refund_amount: $refund_amount,
            total_refunded: $total_refunded,
            credit_refund: $credit_refund,
            card_refund: $card_refund,
            is_full_refund: $is_full_refund,
            stripe_refund_id: $stripe_refund_id,
            actor_name: $actor_name,
        );

        if (! $send_client_notification) {
            return;
        }

        SendClientInvoiceRefundedNotificationJob::dispatch(
            invoice_id: (string) $invoice->id,
            refund_amount: $refund_amount,
            total_refunded: $total_refunded,
            credit_refund: $credit_refund,
            card_refund: $card_refund,
            is_full_refund: $is_full_refund,
        );

        InvoiceHistory::create([
            'invoice_id'     => $invoice->id,
            'event'          => 'refund notification sent to client',
            'description'    => ($is_full_refund ? 'Refund' : 'Partial refund')
                . ' confirmation email queued for the client ($' . number_format($refund_amount, 2) . ').',
            'actor_id'       => null,
            'actor_name'     => 'System',
            'actor_initials' => 'SY',
            'actor_type'     => 'system',
        ]);
    }

    private function recordInvoiceTransaction(Invoice $invoice): void
    {
        $raw_method = strtolower((string) $invoice->payment_method);

        if (str_contains($raw_method, 'credit card') || str_contains($raw_method, 'card')) {
            $payment_method = 'credit_card';
            $type           = 'purchase';
        } else {
            $payment_method = 'account_credits';
            $type           = 'credit_payment';
        }

        Transaction::create([
            'user_id'        => $invoice->user_id,
            'type'           => $type,
            'status'         => 'success',
            'amount'         => $invoice->total_amount,
            'payment_method' => $payment_method,
            'invoice_id'     => (string) $invoice->id,
            'description'    => "Payment recorded for invoice {$invoice->invoice_number}.",
        ]);
    }
}
