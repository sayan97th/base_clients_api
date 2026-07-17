<?php

namespace App\Http\Controllers\Invoice;

use App\Events\PaymentCompleted;
use App\Http\Controllers\Controller;
use App\Jobs\SendAdminInvoicePaidNotificationJob;
use App\Mail\PaymentSuccessfulEmail;
use App\Models\ContentBriefOrder;
use App\Models\ContentOptimizationOrder;
use App\Models\Invoice;
use App\Models\InvoiceHistory;
use App\Models\LinkBuildingOrder;
use App\Models\NewContentOrder;
use App\Models\Transaction;
use App\Models\User;
use App\Services\OrderDetailsService;
use App\Services\StripePublicPaymentService;
use App\Services\StripeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class InvoicePayController extends Controller
{
    private const PAYABLE_STATUSES = ['unpaid', 'overdue'];

    private const ORDER_MODELS = [
        LinkBuildingOrder::class,
        NewContentOrder::class,
        ContentOptimizationOrder::class,
        ContentBriefOrder::class,
    ];

    public function __construct(
        protected StripeService $stripe_service,
        protected StripePublicPaymentService $public_payment_service,
        protected OrderDetailsService $order_details_service,
    ) {}

    /**
     * POST /api/invoices/{unique_id}/pay
     *
     * Unified endpoint for authenticated and public share-link payments.
     * Presence of an Authorization header determines which flow runs.
     */
    public function pay(Request $request, string $unique_id): JsonResponse
    {
        if ($request->hasHeader('Authorization')) {
            return $this->payAuthenticated($request, $unique_id);
        }

        return $this->payPublic($request, $unique_id);
    }

    private function payAuthenticated(Request $request, string $unique_id): JsonResponse
    {
        try {
            /** @var User|null $user */
            $user = auth('api')->user();
        } catch (\Exception) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $user->is_active) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $request->validate([
            'payment_method'    => ['required', 'string', Rule::in(['account_balance', 'credit_card'])],
            'payment_intent_id' => [
                Rule::requiredIf(fn () => $request->input('payment_method') === 'credit_card'),
                'nullable',
                'string',
            ],
        ]);

        $invoice = Invoice::where('unique_id', $unique_id)
            ->where('user_id', $user->id)
            ->with(['lineItems', 'billedTo', 'couponDiscounts'])
            ->first();

        if (! $invoice) {
            $exists = Invoice::where('unique_id', $unique_id)->exists();

            return $exists
                ? response()->json(['message' => 'This invoice does not belong to your account.'], 403)
                : response()->json(['message' => 'Invoice not found.'], 404);
        }

        if (! in_array($invoice->status, self::PAYABLE_STATUSES, true)) {
            return response()->json(['message' => 'This invoice cannot be paid in its current status.'], 400);
        }

        $payment_method    = $request->input('payment_method');
        $payment_intent_id = $request->input('payment_intent_id');

        if ($payment_method === 'account_balance') {
            $result = $this->payViaAccountBalance($invoice, $user);
        } else {
            $result = $this->payViaCreditCard($invoice, $user, $payment_intent_id);
        }

        if (! $result['success']) {
            return response()->json(['message' => $result['message']], $result['status_code'] ?? 422);
        }

        $this->updatePaymentPendingOrders($invoice, $payment_intent_id);

        $invoice->refresh()->load(['lineItems', 'billedTo', 'couponDiscounts']);

        // Send all payment notifications (client email + admin email + admin in-app).
        // These are non-critical: failures are logged but never roll back the payment.
        $this->dispatchPaymentNotifications($invoice, $user);

        return response()->json([
            'data'    => $this->buildInvoiceDetail($invoice),
            'message' => 'Invoice paid successfully.',
        ]);
    }

    private function payPublic(Request $request, string $unique_id): JsonResponse
    {
        $request->validate([
            'payment_intent_id' => ['required', 'string'],
            'token'             => ['required', 'string'],
        ]);

        $invoice = Invoice::where('unique_id', $unique_id)->first();

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

        $result = $this->public_payment_service->confirmPublicInvoicePayment(
            $invoice,
            $request->input('payment_intent_id'),
            $request->input('token')
        );

        $status_code = $result['status_code'] ?? 200;

        if (! $result['success']) {
            return response()->json(['message' => $result['error']], $status_code);
        }

        $this->updatePaymentPendingOrders($invoice, $request->input('payment_intent_id'));

        return response()->json(['message' => $result['message']]);
    }

    /**
     * Mark invoice as paid via account balance.
     * Wrapped in a DB transaction: if the history record fails, the status update is rolled back.
     */
    private function payViaAccountBalance(Invoice $invoice, User $user): array
    {
        try {
            DB::transaction(function () use ($invoice, $user) {
                $invoice->status         = 'paid';
                $invoice->date_paid      = now();
                $invoice->payment_method = 'Account Balance';
                $invoice->save();

                InvoiceHistory::create([
                    'invoice_id'     => $invoice->id,
                    'event'          => 'invoice_paid',
                    'description'    => 'Invoice paid via Account Balance.',
                    'actor_id'       => $user->id,
                    'actor_name'     => $user->full_name ?? $user->email,
                    'actor_initials' => $this->buildInitials($user->full_name ?? $user->email),
                    'actor_type'     => 'client',
                ]);

                Transaction::create([
                    'user_id'        => $user->id,
                    'type'           => 'credit_payment',
                    'status'         => 'success',
                    'amount'         => $invoice->total_amount,
                    'payment_method' => 'account_credits',
                    'invoice_id'     => (string) $invoice->id,
                    'description'    => "Invoice {$invoice->invoice_number} paid via Account Balance.",
                ]);
            });
        } catch (\Exception $e) {
            logger()->error("Account balance payment failed for invoice {$invoice->unique_id}", [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);

            return [
                'success'     => false,
                'message'     => 'Payment processing failed. Please try again or contact support.',
                'status_code' => 500,
            ];
        }

        return ['success' => true];
    }

    /**
     * Verify the Stripe PaymentIntent (authorized but not yet captured) and mark
     * the invoice as paid. Captures the Stripe authorization only after the DB
     * transaction commits — if the transaction fails the authorization is voided
     * so the customer is never charged without a recorded payment.
     */
    private function payViaCreditCard(Invoice $invoice, User $user, string $payment_intent_id): array
    {
        $verify_result = $this->stripe_service->verifyPaymentIntent($payment_intent_id, $invoice->total_amount);

        if (! $verify_result['verified']) {
            return [
                'success'     => false,
                'message'     => $verify_result['message'],
                'status_code' => 402,
            ];
        }

        try {
            DB::transaction(function () use ($invoice, $user, $payment_intent_id) {
                $invoice->status            = 'paid';
                $invoice->date_paid         = now();
                $invoice->payment_method    = 'Credit Card';
                $invoice->payment_intent_id = $payment_intent_id;
                $invoice->save();

                InvoiceHistory::create([
                    'invoice_id'     => $invoice->id,
                    'event'          => 'invoice_paid',
                    'description'    => "Invoice paid via Credit Card. PaymentIntent: {$payment_intent_id}",
                    'actor_id'       => $user->id,
                    'actor_name'     => $user->full_name ?? $user->email,
                    'actor_initials' => $this->buildInitials($user->full_name ?? $user->email),
                    'actor_type'     => 'client',
                ]);

                Transaction::create([
                    'user_id'           => $user->id,
                    'type'              => 'purchase',
                    'status'            => 'success',
                    'amount'            => $invoice->total_amount,
                    'payment_method'    => 'credit_card',
                    'payment_intent_id' => $payment_intent_id,
                    'invoice_id'        => (string) $invoice->id,
                    'description'       => "Invoice {$invoice->invoice_number} paid via Credit Card.",
                ]);
            });
        } catch (\Exception $e) {
            logger()->error("Invoice payment DB record failed — voiding Stripe authorization for invoice {$invoice->unique_id}", [
                'payment_intent_id' => $payment_intent_id,
                'user_id'           => $user->id,
                'error'             => $e->getMessage(),
            ]);

            // DB transaction failed — void the Stripe authorization so customer is not charged
            $this->stripe_service->cancelPaymentIntent($payment_intent_id);

            return [
                'success'     => false,
                'message'     => 'Payment could not be recorded. Your authorization has been voided — you will not be charged. Please try again or contact support.',
                'status_code' => 500,
            ];
        }

        // DB committed — now capture the authorized payment
        $capture_result = $this->stripe_service->capturePaymentIntent($payment_intent_id);

        if (! $capture_result['success']) {
            logger()->critical("Stripe capture FAILED after invoice DB commit — invoice paid but payment not collected for {$invoice->unique_id}", [
                'payment_intent_id' => $payment_intent_id,
                'user_id'           => $user->id,
                'capture_error'     => $capture_result['message'] ?? 'Unknown error',
            ]);
        }

        return ['success' => true];
    }

    /**
     * Send all post-payment notifications.
     * Non-critical: errors are logged but never bubble up to the client response.
     */
    private function dispatchPaymentNotifications(Invoice $invoice, User $user): void
    {
        // Client confirmation email
        try {
            Mail::to($user->email)->queue(new PaymentSuccessfulEmail($user, $invoice));
        } catch (\Exception $e) {
            logger()->warning("Failed to queue client payment confirmation email for invoice {$invoice->unique_id}", [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
        }

        // Admin email + admin in-app notifications
        try {
            SendAdminInvoicePaidNotificationJob::dispatch($invoice->id);

            $payer_name = $user->full_name ?? $user->email;

            User::whereHas('roles', fn ($q) => $q->whereIn('name', ['super_admin', 'admin']))
                ->where('is_active', true)
                ->each(function (User $admin) use ($invoice, $payer_name) {
                    event(new PaymentCompleted(
                        user:           $admin,
                        payer_name:     $payer_name,
                        amount:         (float) $invoice->total_amount,
                        invoice_number: $invoice->invoice_number,
                        link:           '/admin/invoices/' . $invoice->id,
                        invoice:        $invoice,
                    ));
                });
        } catch (\Exception $e) {
            logger()->warning("Failed to dispatch admin payment notifications for invoice {$invoice->unique_id}", [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * After a deferred invoice is paid, transition all associated
     * payment_pending orders so work can begin. An order whose intake details
     * are still missing lands in `pending_details` (staying visible on the
     * dashboards) instead of `new_request`; a complete Link Building order also
     * has its turnaround clock started. Delegated to OrderDetailsService so the
     * paid-order transition logic lives in exactly one place.
     */
    private function updatePaymentPendingOrders(Invoice $invoice, ?string $payment_intent_id): void
    {
        $query = function (string $model) use ($invoice) {
            if ($invoice->session_id) {
                return $model::where('session_id', $invoice->session_id)
                    ->where('status', 'payment_pending');
            }

            if ($invoice->order_id) {
                return $model::where('id', $invoice->order_id)
                    ->where('status', 'payment_pending');
            }

            return null;
        };

        foreach (self::ORDER_MODELS as $model) {
            $builder = $query($model);

            if ($builder === null) {
                return;
            }

            foreach ($builder->get() as $order) {
                $order->payment_intent_id = $payment_intent_id;
                $order->save();

                // Resolves to new_request (details complete) or pending_details,
                // and starts the Link Building clock when the order is complete.
                $this->order_details_service->applyPaidStatus($order);
            }
        }
    }

    private function buildInvoiceDetail(Invoice $invoice): array
    {
        $billed_to        = $invoice->billedTo;
        $bulk_discount    = (float) ($invoice->discount_amount ?? 0);
        $coupon_discounts = $invoice->couponDiscounts->map(fn ($cd) => [
            'code'            => $cd->code,
            'name'            => $cd->name ?? '',
            'discount_type'   => $cd->discount_type,
            'discount_value'  => $cd->discount_value,
            'discount_amount' => '$' . number_format($cd->discount_amount, 2),
        ])->values()->all();

        return [
            'invoice_number'   => $invoice->invoice_number,
            'unique_id'        => $invoice->unique_id,
            'date_issued'      => $invoice->date_issued?->format('M j, Y'),
            'date_paid'        => $invoice->date_paid?->format('M j, Y'),
            'date_due'         => $invoice->date_due?->format('M j, Y'),
            'payment_method'   => $invoice->payment_method,
            'status'           => $invoice->status,
            'subtotal'         => $this->formatAmount($invoice->subtotal_amount, $invoice->currency_type),
            'discount'         => $bulk_discount > 0 ? $this->formatAmount($bulk_discount, $invoice->currency_type) : null,
            'discount_type'    => $invoice->discount_type,
            'total'            => $this->formatAmount($invoice->total_amount, $invoice->currency_type),
            'credit'           => $this->formatCredit((float) ($invoice->credit_amount ?? 0), $invoice->currency_type),
            'notes'            => $invoice->notes,
            'billed_to'        => $billed_to ? [
                'company_name'        => $billed_to->company_name,
                'company_description' => $billed_to->company_description,
                'address_line_1'      => $billed_to->address_line_1,
                'address_line_2'      => $billed_to->address_line_2,
                'state'               => $billed_to->state,
                'country'             => $billed_to->country,
            ] : null,
            'line_items'       => $invoice->lineItems->map(fn ($item) => [
                'item_name'    => $item->item_name,
                'price'        => $this->formatAmount($item->price, $invoice->currency_type),
                'quantity'     => $item->quantity,
                'item_total'   => $this->formatAmount($item->item_total, $invoice->currency_type),
                'product_type' => $item->product_type,
            ])->values(),
            'coupon_discounts' => $coupon_discounts,
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

    private function buildInitials(string $name): string
    {
        $parts = array_filter(explode(' ', trim($name)));

        if (count($parts) >= 2) {
            return strtoupper(mb_substr($parts[0], 0, 1) . mb_substr(end($parts), 0, 1));
        }

        return strtoupper(mb_substr($name, 0, 2));
    }
}
