<?php

namespace App\Http\Controllers\Client\Cart;

use App\Events\LinkBuildingOrderPlaced;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cart\CheckoutCartRequest;
use App\Http\Requests\Cart\UpsertCartRequest;
use App\Jobs\SendAdminInvoicePaidNotificationJob;
use App\Mail\PaymentSuccessfulEmail;
use App\Models\Cart;
use App\Models\ContentBriefOrder;
use App\Models\ContentOptimizationOrder;
use App\Models\Coupon;
use App\Models\CreditTransaction;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Models\DrTier;
use App\Models\LinkBuildingOrder;
use App\Models\LinkBuildingOrderPlacement;
use App\Models\NewContentOrder;
use App\Models\User;
use App\Services\CouponService;
use App\Services\InvoiceService;
use App\Services\OrderDetailsService;
use App\Services\StripeService;
use App\Services\TierPricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class CartController extends Controller
{
    private const BULK_DISCOUNT_THRESHOLD = 10;
    private const BULK_DISCOUNT_RATE      = 0.10;

    public function __construct(
        protected StripeService $stripeService,
        protected CouponService $couponService,
        protected InvoiceService $invoiceService,
        protected OrderDetailsService $orderDetailsService,
        protected TierPricingService $tierPricingService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $cart = Cart::where('user_id', $request->user()->id)->first();

        return response()->json(['data' => $cart?->payload]);
    }

    public function upsert(UpsertCartRequest $request): JsonResponse
    {
        Cart::updateOrCreate(
            ['user_id' => $request->user()->id],
            ['payload' => $request->validated()]
        );

        return response()->json(['message' => 'Cart saved successfully.']);
    }

    public function destroy(Request $request): JsonResponse
    {
        Cart::where('user_id', $request->user()->id)->delete();

        return response()->json(['message' => 'Cart deleted successfully.']);
    }

    public function checkout(CheckoutCartRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $session_id = $request->input('session_id');

        if ($session_id) {
            $already_processed = LinkBuildingOrder::where('session_id', $session_id)->exists()
                || ContentOptimizationOrder::where('session_id', $session_id)->exists()
                || NewContentOrder::where('session_id', $session_id)->exists()
                || ContentBriefOrder::where('session_id', $session_id)->exists();

            if ($already_processed) {
                return response()->json([
                    'message' => 'This checkout session has already been processed.',
                ], 409);
            }
        }

        $payment_method_id  = $request->input('payment_method_id');
        $is_credits_payment = str_starts_with($payment_method_id, 'credits_');

        // Atomic credits format: credits_pay_<amount> — credit deduction happens
        // inside the DB transaction so it is fully rolled back on any failure.
        // Legacy format: credits_<transaction_id> — the transaction was created
        // before checkout; a reversal is issued in the catch block if needed.
        $is_atomic_credits = str_starts_with($payment_method_id, 'credits_pay_');
        $credits_amount    = null;

        // Hybrid payment: Stripe PI + partial credits applied as discount.
        // credits_amount holds the number of credits to atomically deduct inside the DB
        // transaction; the card covers the remaining balance.
        $hybrid_credits_amount = (float) ($request->input('credits_amount', 0));
        $is_hybrid_payment     = ! $is_credits_payment && $hybrid_credits_amount > 0;

        // Whether any portion of this order is paid with account credits — either a
        // pure credits payment or a hybrid (card + partial credits) payment. Credits
        // already represent a discounted value, so when any credits are involved no
        // bulk discounts or coupon codes may be applied on top of them. This flag is
        // the single source of truth that keeps the server in sync with the frontend
        // Order Summary, which disables coupons/discounts as soon as credits are applied.
        $any_credits_applied = $is_credits_payment || $is_hybrid_payment;
        $skip_discounts      = $any_credits_applied;

        // Reject coupon codes for any credits-backed payment (pure or hybrid).
        if ($any_credits_applied && ! empty($request->input('coupon_ids', []))) {
            return response()->json([
                'message' => 'Coupon codes cannot be applied when paying with account credits.',
            ], 422);
        }

        // Calculate the expected order total server-side so it can be verified
        // against the Stripe PaymentIntent amount before any data is written.
        // This always resolves prices from the current tier records, not the
        // client-submitted unit_price, so it can throw if a tier was removed
        // between page load and checkout.
        try {
            $expected_total = $this->calculateExpectedTotal($request);
        } catch (\DomainException $e) {
            return response()->json([
                'message' => 'One or more selected items are no longer available. Please refresh your cart and try again.',
                'error'   => 'tier_unavailable',
            ], 422);
        }

        if ($is_credits_payment) {
            if ($is_atomic_credits) {
                $credits_amount = (int) substr($payment_method_id, strlen('credits_pay_'));

                if ($credits_amount <= 0) {
                    return response()->json([
                        'message' => 'Invalid credits amount.',
                        'error'   => 'invalid_credits_amount',
                    ], 422);
                }
                // Balance check and deduction happen atomically inside DB::transaction below.
            } else {
                // Legacy pre-deducted credits flow.
                $credit_transaction_id = (int) substr($payment_method_id, strlen('credits_'));
                $credit_transaction    = CreditTransaction::find($credit_transaction_id);

                if (
                    ! $credit_transaction
                    || $credit_transaction->user_id !== $user->id
                    || $credit_transaction->type !== 'debit'
                ) {
                    return response()->json([
                        'message' => 'Invalid credit payment reference.',
                        'error'   => 'The provided credit transaction is not valid for this payment.',
                    ], 422);
                }
            }
        } else {
            // Verify the Stripe PaymentIntent before writing anything to the database.
            // For hybrid payments the expected Stripe charge = total - credits; for pure
            // card payments expected Stripe charge = total. This prevents a tampered or
            // reused intent from covering a different order amount.
            $expected_stripe_amount = $is_hybrid_payment
                ? max(0.0, round($expected_total - $hybrid_credits_amount, 2))
                : $expected_total;

            $stripe_result = $this->stripeService->verifyPaymentIntent($payment_method_id, $expected_stripe_amount);

            if (! $stripe_result['verified']) {
                return response()->json([
                    'message' => 'Payment could not be processed.',
                    'error'   => $stripe_result['message'] ?? 'Your payment could not be verified.',
                ], 402);
            }

            // For hybrid payments, do a quick pre-check of the credit balance.
            // With manual capture, the card has only been authorized (not yet charged),
            // so we cancel the authorization instead of issuing a refund.
            if ($is_hybrid_payment) {
                $fresh_balance = User::where('id', $user->id)->value('credit_balance');
                if ($hybrid_credits_amount > $fresh_balance) {
                    $cancel_result = $this->stripeService->cancelPaymentIntent($payment_method_id);

                    if (! $cancel_result['success']) {
                        Log::critical('Stripe authorization void FAILED after hybrid credit pre-check failed — manual action required.', [
                            'user_id'           => $user->id,
                            'payment_method_id' => $payment_method_id,
                            'cancel_error'      => $cancel_result['message'] ?? 'Unknown error',
                        ]);
                        return response()->json([
                            'message' => 'Insufficient credit balance. We were unable to void the payment authorization — please contact support immediately with reference: ' . $payment_method_id,
                            'error'   => 'insufficient_credits',
                        ], 422);
                    }

                    return response()->json([
                        'message' => 'Insufficient credit balance. Your card authorization has been automatically voided — you will not be charged.',
                        'error'   => 'insufficient_credits',
                    ], 422);
                }
            }
        }

        // Validate that all submitted coupon IDs still exist
        $coupon_ids     = $request->input('coupon_ids', []);
        $coupon_models  = [];

        foreach ($coupon_ids as $coupon_id) {
            $coupon = Coupon::find($coupon_id);

            if (!$coupon) {
                return response()->json(['message' => 'One or more coupons are no longer valid.'], 422);
            }

            $coupon_models[$coupon_id] = $coupon;
        }

        $billing       = $request->input('billing');
        $order_title   = $request->input('order_title');
        $order_notes   = $request->input('order_notes');
        $created_orders = [];

        $link_building_items        = $request->input('link_building_items');
        $content_optimization_items = $request->input('content_optimization_items');
        $new_content_items          = $request->input('new_content_items');
        $content_brief_items        = $request->input('content_brief_items');

        $session_id    = $request->input('session_id') ?? (string) Str::uuid();
        $session_title = $order_title;

        // "Skip for now": park every created order in pending_details regardless of
        // how much intake data was entered, so the client can review/complete later.
        $defer_details = $request->boolean('defer_details');

        // $effective_payment_method_id starts as the raw payment_method_id but is
        // replaced with credits_<tx_id> inside the transaction for atomic credits.
        $effective_payment_method_id = $payment_method_id;

        try {
            DB::transaction(function () use (
                $user, $billing, $order_title, $order_notes,
                $coupon_models, $session_id, $session_title, $is_credits_payment,
                $is_atomic_credits, $credits_amount,
                $is_hybrid_payment, $hybrid_credits_amount, $skip_discounts,
                $link_building_items, $content_optimization_items,
                $new_content_items, $content_brief_items, $defer_details,
                &$effective_payment_method_id,
                &$created_orders
            ) {
                // For the atomic credits flow, deduct credits inside this transaction
                // so that the credit deduction and all order creation are fully atomic.
                // If anything fails below, both the credit deduction and the orders
                // are rolled back together — no credits are lost.
                if ($is_atomic_credits) {
                    $fresh_user = User::where('id', $user->id)->lockForUpdate()->first();

                    if ($fresh_user->credit_balance < $credits_amount) {
                        throw new \DomainException('insufficient_balance');
                    }

                    $fresh_user->decrement('credit_balance', $credits_amount);

                    $credit_tx = CreditTransaction::create([
                        'user_id'     => $user->id,
                        'amount'      => $credits_amount,
                        'type'        => 'debit',
                        'description' => 'Order payment via account credits',
                        'created_by'  => null,
                    ]);

                    // Use the real transaction ID as the stored payment reference.
                    $effective_payment_method_id = 'credits_' . $credit_tx->id;
                }

                // For hybrid payments (Stripe card + credits), atomically deduct the
                // credit portion here so it rolls back with the orders if anything fails.
                // The card is only authorized at this point — capture happens after the
                // transaction commits successfully.
                if ($is_hybrid_payment) {
                    $fresh_user = User::where('id', $user->id)->lockForUpdate()->first();

                    if ($fresh_user->credit_balance < $hybrid_credits_amount) {
                        throw new \DomainException('insufficient_balance');
                    }

                    $fresh_user->decrement('credit_balance', $hybrid_credits_amount);

                    CreditTransaction::create([
                        'user_id'     => $user->id,
                        'amount'      => $hybrid_credits_amount,
                        'type'        => 'debit',
                        'description' => 'Credit discount applied to order (hybrid payment)',
                        'created_by'  => null,
                    ]);
                }

                if (! empty($link_building_items)) {
                    $created_orders[] = $this->createLinkBuildingOrder(
                        $user, $link_building_items, $billing,
                        $effective_payment_method_id, $coupon_models,
                        $order_title, $order_notes, $session_id, $session_title,
                        $skip_discounts, $defer_details
                    );
                }

                if (! empty($content_optimization_items)) {
                    $created_orders[] = $this->createContentOptimizationOrder(
                        $user, $content_optimization_items, $billing,
                        $effective_payment_method_id, $coupon_models,
                        $order_title, $order_notes, $session_id, $session_title,
                        $skip_discounts, $defer_details
                    );
                }

                if (! empty($new_content_items)) {
                    $created_orders[] = $this->createNewContentOrder(
                        $user, $new_content_items, $billing,
                        $effective_payment_method_id, $coupon_models,
                        $order_title, $order_notes, $session_id, $session_title,
                        $skip_discounts, $defer_details
                    );
                }

                if (! empty($content_brief_items)) {
                    $created_orders[] = $this->createContentBriefOrder(
                        $user, $content_brief_items, $billing,
                        $effective_payment_method_id, $coupon_models,
                        $order_title, $order_notes, $session_id, $session_title,
                        $skip_discounts, $defer_details
                    );
                }

                Cart::where('user_id', $user->id)->delete();
            });
        } catch (\DomainException $e) {
            // Insufficient balance: the DB transaction rolled back automatically so
            // no credits or orders were written. For hybrid payments the card was
            // authorized (not yet captured) — void the authorization immediately.
            if ($e->getMessage() === 'insufficient_balance') {
                $this->recordFailedTransaction($user, $payment_method_id, $session_id, $session_title, $is_credits_payment, $is_hybrid_payment, 'Insufficient credit balance.');

                if ($is_hybrid_payment) {
                    $cancel_result = $this->stripeService->cancelPaymentIntent($payment_method_id);
                    if ($cancel_result['success']) {
                        Log::info('Stripe authorization voided after hybrid payment credit balance insufficient.', [
                            'user_id'           => $user->id,
                            'payment_method_id' => $payment_method_id,
                        ]);
                        return response()->json([
                            'message' => 'Insufficient credit balance. Your card authorization has been automatically voided — you will not be charged.',
                            'error'   => 'insufficient_credits',
                        ], 422);
                    }

                    Log::critical('Stripe authorization void FAILED after hybrid payment credit balance insufficient — manual action required.', [
                        'user_id'           => $user->id,
                        'payment_method_id' => $payment_method_id,
                        'cancel_error'      => $cancel_result['message'] ?? 'Unknown error',
                    ]);
                    return response()->json([
                        'message' => 'Insufficient credit balance. We were unable to void the payment authorization — please contact support immediately with reference: ' . $payment_method_id,
                        'error'   => 'insufficient_credits',
                    ], 422);
                }

                return response()->json([
                    'message' => 'Insufficient credit balance. Please check your account credits and try again.',
                    'error'   => 'insufficient_credits',
                ], 422);
            }

            if (str_starts_with($e->getMessage(), 'tier_not_found:')) {
                $this->recordFailedTransaction($user, $payment_method_id, $session_id, $session_title, $is_credits_payment, $is_hybrid_payment, $e->getMessage());

                return response()->json([
                    'message' => 'One or more selected items are no longer available. Please refresh your cart and try again.',
                    'error'   => 'tier_unavailable',
                ], 422);
            }

            $this->recordFailedTransaction($user, $payment_method_id, $session_id, $session_title, $is_credits_payment, $is_hybrid_payment, $e->getMessage());

            Log::error('Unexpected domain error during cart checkout.', [
                'user_id'           => $user->id,
                'payment_method_id' => $payment_method_id,
                'error'             => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'An unexpected error occurred while creating your orders. Please contact support.',
                'error'   => 'order_creation_failed',
            ], 500);
        } catch (Throwable $e) {
            Log::error('Unified cart checkout failed — DB transaction rolled back, voiding Stripe authorization.', [
                'user_id'           => $user->id,
                'payment_method_id' => $payment_method_id,
                'error'             => $e->getMessage(),
                'trace'             => $e->getTraceAsString(),
            ]);

            $refund_note = '';

            if (! $is_credits_payment) {
                // The DB transaction failed and rolled back — no orders were created.
                // The card was only authorized (capture_method: manual), never charged.
                // Cancel the authorization so the customer is never billed.
                // For hybrid payments the DB transaction also rolled back the credit
                // deduction atomically, so only the Stripe authorization needs voiding.
                $cancel_result = $this->stripeService->cancelPaymentIntent($payment_method_id);

                if ($cancel_result['success']) {
                    $was_refunded = isset($cancel_result['voided']) && ! $cancel_result['voided'];
                    if ($was_refunded) {
                        Log::info('Automatic refund issued after failed checkout (payment was already captured).', [
                            'user_id'           => $user->id,
                            'payment_method_id' => $payment_method_id,
                            'refund_id'         => $cancel_result['refund_id'] ?? null,
                        ]);
                        $refund_note = ' Your payment has been automatically refunded. Funds typically appear within 5–10 business days.';
                    } else {
                        Log::info('Stripe authorization voided after failed checkout — customer was not charged.', [
                            'user_id'           => $user->id,
                            'payment_method_id' => $payment_method_id,
                        ]);
                        $refund_note = ' Your payment authorization has been automatically voided — you will not be charged.';
                    }
                } else {
                    Log::critical('Stripe authorization void FAILED after failed checkout — manual action required.', [
                        'user_id'           => $user->id,
                        'payment_method_id' => $payment_method_id,
                        'cancel_error'      => $cancel_result['message'] ?? 'Unknown error',
                    ]);
                    $refund_note = ' We were unable to automatically void the payment authorization. Please contact support immediately with your payment reference: ' . $payment_method_id;
                }
            } elseif (! $is_atomic_credits) {
                // Legacy credits flow: credits were pre-deducted before checkout was
                // called, so we must reverse the deduction if order creation failed.
                // For the atomic flow, the DB transaction already rolled back and no
                // credits were deducted — no reversal needed.
                $legacy_tx_id = (int) substr($payment_method_id, strlen('credits_'));
                $legacy_tx    = CreditTransaction::find($legacy_tx_id);

                if ($legacy_tx && $legacy_tx->user_id === $user->id) {
                    try {
                        DB::transaction(function () use ($user, $legacy_tx) {
                            $fresh_user = User::where('id', $user->id)->lockForUpdate()->first();
                            $fresh_user->increment('credit_balance', $legacy_tx->amount);
                            CreditTransaction::create([
                                'user_id'     => $user->id,
                                'amount'      => $legacy_tx->amount,
                                'type'        => 'credit',
                                'description' => "Automatic reversal of transaction #{$legacy_tx->id}: order creation failed",
                                'created_by'  => null,
                            ]);
                        });

                        Log::info('Automatic credit reversal issued after failed checkout.', [
                            'user_id'                 => $user->id,
                            'original_transaction_id' => $legacy_tx->id,
                            'amount'                  => $legacy_tx->amount,
                        ]);
                        $refund_note = ' Your credits have been automatically restored to your account.';
                    } catch (Throwable $reversal_error) {
                        Log::critical('Automatic credit reversal FAILED after failed checkout — manual action required.', [
                            'user_id'                 => $user->id,
                            'original_transaction_id' => $legacy_tx->id,
                            'amount'                  => $legacy_tx->amount,
                            'reversal_error'          => $reversal_error->getMessage(),
                        ]);
                        $refund_note = ' We were unable to automatically restore your credits. Please contact support immediately with reference: credits_' . $legacy_tx->id;
                    }
                }
            }

            $this->recordFailedTransaction($user, $payment_method_id, $session_id, $session_title, $is_credits_payment, $is_hybrid_payment, $e->getMessage());

            return response()->json([
                'message' => 'An error occurred while creating your orders.' . $refund_note . ' Please contact support if you need assistance.',
                'error'   => 'order_creation_failed',
            ], 500);
        }

        // Capture the Stripe authorization now that the DB transaction has committed
        // and all orders exist. With capture_method: manual the card hold is converted
        // to an actual charge here — if this fails the orders are already created and
        // support must follow up with the customer to collect payment.
        if (! $is_credits_payment) {
            $capture_result = $this->stripeService->capturePaymentIntent($payment_method_id);

            if (! $capture_result['success']) {
                Log::critical('Stripe capture FAILED after successful order creation — orders exist but payment not collected, manual action required.', [
                    'user_id'           => $user->id,
                    'payment_method_id' => $payment_method_id,
                    'capture_error'     => $capture_result['message'] ?? 'Unknown error',
                    'order_ids'         => array_column($created_orders, 'order_id'),
                ]);
            }
        }

        // Increment coupon usage counts after the transaction commits
        // (skipped for any credits-backed payment since no coupons are permitted)
        if (! $any_credits_applied) {
            foreach ($coupon_models as $coupon) {
                $coupon->increment('times_used');
            }
        }

        // Fire product-specific events
        foreach ($created_orders as $entry) {
            if ($entry['product_type'] === 'link_building') {
                event(new LinkBuildingOrderPlaced($user, $entry['model'], $entry['total_links']));
            }
        }

        // Determine invoice payment fields based on payment method used
        $invoice_payment_method   = $is_credits_payment ? 'Account Balance' : 'Credit Card';
        $invoice_currency_type    = $is_credits_payment ? 'credits' : 'usd';
        $total_credits_used       = $is_credits_payment
            ? round(array_sum(array_column($created_orders, 'total_amount')), 2)
            : 0.0;
        $invoice_payment_intent_id = $is_credits_payment ? null : $payment_method_id;

        // Create invoice(s): one combined invoice for multi-product, one per order for single-product
        $checkout_invoice = null;

        if ($session_id && count($created_orders) > 1) {
            $checkout_invoice = $this->invoiceService->createForMultiProductSession(
                user:              $user,
                session_id:        $session_id,
                session_title:     $session_title,
                product_entries:   $created_orders,
                payment_method:    $invoice_payment_method,
                currency_type:     $invoice_currency_type,
                credit_amount:     $total_credits_used,
                payment_intent_id: $invoice_payment_intent_id,
            );
        } else {
            $entry        = $created_orders[0];
            $order_credit = $is_credits_payment ? $entry['total_amount'] : 0.0;
            $checkout_invoice = match ($entry['product_type']) {
                'link_building' => $this->invoiceService->createForLinkBuildingOrder(
                    user:              $user,
                    order:             $entry['model'],
                    payment_method:    $invoice_payment_method,
                    currency_type:     $invoice_currency_type,
                    credit_amount:     $order_credit,
                    total_links:       $entry['total_links'],
                    payment_intent_id: $invoice_payment_intent_id,
                ),
                'new_content' => $this->invoiceService->createForNewContentOrder(
                    user:              $user,
                    order:             $entry['model'],
                    payment_method:    $invoice_payment_method,
                    currency_type:     $invoice_currency_type,
                    credit_amount:     $order_credit,
                    payment_intent_id: $invoice_payment_intent_id,
                ),
                'content_optimization' => $this->invoiceService->createForContentOptimizationOrder(
                    user:              $user,
                    order:             $entry['model'],
                    payment_method:    $invoice_payment_method,
                    currency_type:     $invoice_currency_type,
                    credit_amount:     $order_credit,
                    payment_intent_id: $invoice_payment_intent_id,
                ),
                'content_brief' => $this->invoiceService->createForContentBriefOrder(
                    user:              $user,
                    order:             $entry['model'],
                    payment_method:    $invoice_payment_method,
                    currency_type:     $invoice_currency_type,
                    credit_amount:     $order_credit,
                    payment_intent_id: $invoice_payment_intent_id,
                ),
                default => null,
            };
        }

        // Dispatch payment notifications — non-critical, errors are logged but never fail the response
        if ($checkout_invoice) {
            $this->dispatchCheckoutNotifications($checkout_invoice, $user);
        }

        $response_orders = array_map(fn ($entry) => [
            'order_id'     => $entry['order_id'],
            'product_type' => $entry['product_type'],
            'total_amount' => $entry['total_amount'],
        ], $created_orders);

        // Record successful transaction
        $tx_total          = round(array_sum(array_column($created_orders, 'total_amount')), 2);
        $first_order_id    = $created_orders[0]['order_id'] ?? null;
        $tx_payment_method = $is_credits_payment ? 'account_credits' : ($is_hybrid_payment ? 'hybrid' : 'credit_card');
        $tx_type           = $is_credits_payment ? 'credit_payment' : ($is_hybrid_payment ? 'hybrid_payment' : 'purchase');
        $tx_pi_id          = $is_credits_payment ? null : $payment_method_id;

        Transaction::create([
            'user_id'           => $user->id,
            'type'              => $tx_type,
            'status'            => 'success',
            'amount'            => $tx_total,
            'payment_method'    => $tx_payment_method,
            'payment_intent_id' => $tx_pi_id,
            'session_id'        => $session_id,
            'session_title'     => $session_title,
            'order_id'          => $first_order_id,
            'description'       => 'Checkout completed successfully for session: ' . $session_id,
        ]);

        return response()->json(['data' => [
            'session_id' => $session_id,
            'orders'     => $response_orders,
        ]]);
    }

    private function recordFailedTransaction(
        User   $user,
        string $payment_method_id,
        ?string $session_id,
        ?string $session_title,
        bool   $is_credits_payment,
        bool   $is_hybrid_payment,
        string $error_message
    ): void {
        try {
            $tx_payment_method = $is_credits_payment ? 'account_credits' : ($is_hybrid_payment ? 'hybrid' : 'credit_card');
            $tx_pi_id          = $is_credits_payment ? null : $payment_method_id;

            Transaction::create([
                'user_id'           => $user->id,
                'type'              => 'failed_purchase',
                'status'            => 'failed',
                'amount'            => 0,
                'payment_method'    => $tx_payment_method,
                'payment_intent_id' => $tx_pi_id,
                'session_id'        => $session_id,
                'session_title'     => $session_title,
                'error_message'     => $error_message,
                'description'       => 'Checkout failed for session: ' . $session_id,
            ]);
        } catch (Throwable $record_error) {
            Log::error('Failed to record failed transaction.', [
                'user_id' => $user->id,
                'error'   => $record_error->getMessage(),
            ]);
        }
    }

    /**
     * Calculate the expected full order total server-side (after bulk/coupon discounts,
     * before any credits deduction) so it can be used for Stripe PaymentIntent amount
     * verification. The caller subtracts credits_amount for hybrid payments.
     *
     * Applies the same bulk-discount and coupon logic used in the create* methods
     * but only to arrive at a final total — order models are not touched here.
     */
    private function calculateExpectedTotal(CheckoutCartRequest $request): float
    {
        $link_building_items        = $request->input('link_building_items', []);
        $content_optimization_items = $request->input('content_optimization_items', []);
        $new_content_items          = $request->input('new_content_items', []);
        $content_brief_items        = $request->input('content_brief_items', []);
        $coupon_ids                 = $request->input('coupon_ids', []);
        $is_credits_payment         = str_starts_with($request->input('payment_method_id', ''), 'credits_');
        // Hybrid payments (card + partial credits) carry a positive credits_amount.
        // Any credits-backed payment skips bulk discounts and coupons so the expected
        // total matches the order totals created with $skip_discounts below.
        $is_credits_payment         = $is_credits_payment || (float) $request->input('credits_amount', 0) > 0;

        // Pre-load coupon models so we can apply them below
        $coupon_models = [];
        foreach ($coupon_ids as $coupon_id) {
            $coupon = Coupon::find($coupon_id);
            if ($coupon) {
                $coupon_models[$coupon_id] = $coupon;
            }
        }

        $grand_total = 0.0;

        // ── Link Building ──
        if (! empty($link_building_items)) {
            $link_building_items = $this->tierPricingService->resolveItemPrices('link_building', $link_building_items);
            $total_links     = 0;
            $subtotal        = 0.0;
            $dr_tier_ids     = [];
            $dr_tier_amounts = [];
            foreach ($link_building_items as $item) {
                $total_links  += (int) $item['quantity'];
                $item_total    = (float) $item['unit_price'] * (int) $item['quantity'];
                $subtotal     += $item_total;

                $tier_id = (string) $item['dr_tier_id'];
                if (! in_array($tier_id, $dr_tier_ids, true)) {
                    $dr_tier_ids[] = $tier_id;
                }
                $dr_tier_amounts[$tier_id] = ($dr_tier_amounts[$tier_id] ?? 0.0) + $item_total;
            }
            $subtotal = round($subtotal, 2);

            $potential_bulk = (! $is_credits_payment && $total_links >= self::BULK_DISCOUNT_THRESHOLD)
                ? round($subtotal * self::BULK_DISCOUNT_RATE, 2)
                : 0.0;

            // Calculate coupon discount on the full subtotal (not post-bulk)
            $potential_coupon = 0.0;
            if (! $is_credits_payment) {
                $temp_amount = $subtotal;
                foreach ($coupon_models as $coupon) {
                    $result = $this->couponService->validateAndCalculate(
                        $coupon,
                        $temp_amount,
                        auth()->id(),
                        $dr_tier_ids,
                        $dr_tier_amounts,
                        ['link_building'],
                        ['link_building' => $subtotal]
                    );
                    if ($result['valid']) {
                        $potential_coupon += $result['discount_amount'];
                        $temp_amount       = round($temp_amount - $result['discount_amount'], 2);
                    }
                }
            }

            // When a coupon is submitted it always overrides the bulk discount
            // regardless of savings amount (admin override intent).
            $effective_discount = $potential_coupon > 0
                ? $potential_coupon
                : $potential_bulk;

            $grand_total += max(0.0, round($subtotal - $effective_discount, 2));
        }

        // ── Content Optimization ──
        if (! empty($content_optimization_items)) {
            $content_optimization_items = $this->tierPricingService->resolveItemPrices('content_optimization', $content_optimization_items);
            $subtotal = 0.0;
            foreach ($content_optimization_items as $item) {
                $subtotal += (float) $item['unit_price'] * (int) $item['quantity'];
            }
            $subtotal = round($subtotal, 2);
            $amount   = $subtotal;
            if (! $is_credits_payment) {
                foreach ($coupon_models as $coupon) {
                    $result = $this->couponService->validateAndCalculate(
                        $coupon, $amount, auth()->id(),
                        [], [], ['content_optimization'], ['content_optimization' => $subtotal]
                    );
                    if ($result['valid']) {
                        $amount = round($amount - $result['discount_amount'], 2);
                    }
                }
            }
            $grand_total += max(0.0, $amount);
        }

        // ── New Content ──
        if (! empty($new_content_items)) {
            $new_content_items = $this->tierPricingService->resolveItemPrices('new_content', $new_content_items);
            $subtotal = 0.0;
            foreach ($new_content_items as $item) {
                $subtotal += (float) $item['unit_price'] * (int) $item['quantity'];
            }
            $subtotal = round($subtotal, 2);
            $amount   = $subtotal;
            if (! $is_credits_payment) {
                foreach ($coupon_models as $coupon) {
                    $result = $this->couponService->validateAndCalculate(
                        $coupon, $amount, auth()->id(),
                        [], [], ['new_content'], ['new_content' => $subtotal]
                    );
                    if ($result['valid']) {
                        $amount = round($amount - $result['discount_amount'], 2);
                    }
                }
            }
            $grand_total += max(0.0, $amount);
        }

        // ── Content Briefs ──
        if (! empty($content_brief_items)) {
            $content_brief_items = $this->tierPricingService->resolveItemPrices('content_brief', $content_brief_items);
            $subtotal = 0.0;
            foreach ($content_brief_items as $item) {
                $subtotal += (float) $item['unit_price'] * (int) $item['quantity'];
            }
            $subtotal = round($subtotal, 2);
            $amount   = $subtotal;
            if (! $is_credits_payment) {
                foreach ($coupon_models as $coupon) {
                    $result = $this->couponService->validateAndCalculate(
                        $coupon, $amount, auth()->id(),
                        [], [], ['content_brief'], ['content_brief' => $subtotal]
                    );
                    if ($result['valid']) {
                        $amount = round($amount - $result['discount_amount'], 2);
                    }
                }
            }
            $grand_total += max(0.0, $amount);
        }

        return round($grand_total, 2);
    }

    private function createLinkBuildingOrder(
        User $user,
        array $items,
        array $billing,
        string $payment_method_id,
        array $coupon_models,
        ?string $order_title,
        ?string $order_notes,
        ?string $session_id,
        ?string $session_title,
        bool $skip_discounts = false,
        bool $defer_details = false
    ): array {
        $items = $this->tierPricingService->resolveItemPrices('link_building', $items);

        $total_links     = 0;
        $subtotal        = 0.0;
        $dr_tier_ids     = [];
        $dr_tier_amounts = [];

        foreach ($items as $item) {
            $total_links  += (int) $item['quantity'];
            $item_total    = (float) $item['unit_price'] * (int) $item['quantity'];
            $subtotal     += $item_total;

            $tier_id = (string) $item['dr_tier_id'];
            if (! in_array($tier_id, $dr_tier_ids, true)) {
                $dr_tier_ids[] = $tier_id;
            }
            $dr_tier_amounts[$tier_id] = ($dr_tier_amounts[$tier_id] ?? 0.0) + $item_total;
        }

        $subtotal = round($subtotal, 2);

        // Bulk discounts and coupons are skipped when paying with credits.
        // Only one discount type applies — whichever saves more.
        $potential_bulk = (! $skip_discounts && $total_links >= self::BULK_DISCOUNT_THRESHOLD)
            ? round($subtotal * self::BULK_DISCOUNT_RATE, 2)
            : 0.0;

        // Calculate coupon discount on the full subtotal (not post-bulk)
        $potential_coupons       = [];
        $potential_coupon_amount = 0.0;

        if (! $skip_discounts) {
            $temp_amount = $subtotal;
            foreach ($coupon_models as $coupon) {
                $result = $this->couponService->validateAndCalculate(
                    $coupon,
                    $temp_amount,
                    $user->id,
                    $dr_tier_ids,
                    $dr_tier_amounts,
                    ['link_building'],
                    ['link_building' => $subtotal]
                );

                if ($result['valid']) {
                    $potential_coupons[]     = ['coupon' => $coupon, 'discount_amount' => $result['discount_amount']];
                    $potential_coupon_amount += $result['discount_amount'];
                    $temp_amount             = round($temp_amount - $result['discount_amount'], 2);
                }
            }
        }

        // When a coupon is explicitly submitted it always overrides the bulk discount
        // (admin override intent) — only one discount type applies per order.
        if ($potential_coupon_amount > 0) {
            $bulk_discount   = 0.0;
            $applied_coupons = $potential_coupons;
        } else {
            $bulk_discount   = $potential_bulk;
            $applied_coupons = [];
        }

        $total_discount = $bulk_discount + array_sum(array_column($applied_coupons, 'discount_amount'));
        $order_total    = max(0.0, round($subtotal - $total_discount, 2));

        $order = LinkBuildingOrder::create([
            'user_id'                  => $user->id,
            'order_title'              => $order_title,
            'order_notes'              => $order_notes,
            'subtotal_before_discount' => $subtotal,
            'total_amount'             => $order_total,
            'status'                   => 'new_request',
            'payment_intent_id'        => $payment_method_id,
            'session_id'               => $session_id,
            'session_title'            => $session_title,
        ]);

        foreach ($applied_coupons as $entry) {
            $order->orderCoupons()->create([
                'coupon_id'       => $entry['coupon']->id,
                'discount_amount' => $entry['discount_amount'],
            ]);
        }

        // Reserve starting sequence number for BL- order IDs once per checkout call
        // so all placements in this order receive consecutive identifiers without
        // issuing a separate MAX query for every individual row.
        $max_bl_num   = LinkBuildingOrderPlacement::whereNotNull('order_id')
            ->whereRaw("order_id REGEXP '^BL-[0-9]+$'")
            ->selectRaw('MAX(CAST(SUBSTRING(order_id, 4) AS UNSIGNED)) as max_num')
            ->value('max_num');
        $next_bl_num  = ($max_bl_num === null ? 0 : (int) $max_bl_num) + 1;

        foreach ($items as $item_data) {
            $item_subtotal = round((float) $item_data['unit_price'] * (int) $item_data['quantity'], 2);

            $item = $order->items()->create([
                'dr_tier_id' => $item_data['dr_tier_id'],
                'quantity'   => $item_data['quantity'],
                'unit_price' => (float) $item_data['unit_price'],
                'subtotal'   => $item_subtotal,
            ]);

            $client_company = trim($user->company ?? '');
            $dr_tier        = DrTier::find($item_data['dr_tier_id']);
            $link_type      = $dr_tier ? $dr_tier->label . ' External' : null;

            // Always create one placement per purchased link so a details-deferred
            // order still exposes the correct number of rows to fill in later.
            // The client may submit fewer placements than the quantity (e.g. a
            // single null placeholder when skipping intake), so pad to quantity.
            $placements_input = $item_data['placements'] ?? [];
            $slot_count       = max((int) $item_data['quantity'], count($placements_input));

            for ($i = 0; $i < $slot_count; $i++) {
                $placement_data = $placements_input[$i] ?? [];
                $keyword        = ($placement_data['keyword'] ?? null) ?: null;
                $landing_page   = ($placement_data['landing_page'] ?? null) ?: null;
                // On "Skip for now" every placement is parked as Pending Details even
                // when both fields were filled — the client wants to review later.
                $has_details    = ! $defer_details && filled($keyword) && filled($landing_page);

                $item->placements()->create([
                    'order_id'     => 'BL-' . $next_bl_num++,
                    'row_index'    => $placement_data['row_index'] ?? $i,
                    'keyword'      => $keyword,
                    'landing_page' => $landing_page,
                    'exact_match'  => $placement_data['exact_match'] ?? false,
                    'client'       => $client_company ?: null,
                    'status'       => $has_details ? 'New Request' : 'Pending Details',
                    'request_date' => now()->format('m/d/Y'),
                    'user_id'      => $user->id,
                    'link_type'    => $link_type,
                ]);
            }
        }

        $order->billing()->create([
            'company'     => $billing['company'] ?: null,
            'address'     => $billing['address'] ?: null,
            'city'        => $billing['city'] ?: null,
            'state'       => $billing['state'] ?: null,
            'country'     => $billing['country'] ?: null,
            'postal_code' => $billing['postal_code'] ?: null,
        ]);

        // Park the order in `pending_details` when keywords/target URLs are
        // missing; a complete order becomes `new_request` and starts its clock.
        $this->orderDetailsService->applyPaidStatus($order, $defer_details);

        return [
            'product_type' => 'link_building',
            'order_id'     => $order->id,
            'total_amount' => $order_total,
            'model'        => $order,
            'total_links'  => $total_links,
        ];
    }

    private function createContentOptimizationOrder(
        User $user,
        array $items,
        array $billing,
        string $payment_method_id,
        array $coupon_models,
        ?string $order_title,
        ?string $order_notes,
        ?string $session_id,
        ?string $session_title,
        bool $skip_discounts = false,
        bool $defer_details = false
    ): array {
        $items = $this->tierPricingService->resolveItemPrices('content_optimization', $items);

        $subtotal = 0.0;

        foreach ($items as $item) {
            $subtotal += (float) $item['unit_price'] * (int) $item['quantity'];
        }

        $subtotal = round($subtotal, 2);

        $applied_coupons = [];
        $current_amount  = $subtotal;

        if (! $skip_discounts) {
            foreach ($coupon_models as $coupon) {
                $result = $this->couponService->validateAndCalculate(
                    $coupon,
                    $current_amount,
                    $user->id,
                    [],
                    [],
                    ['content_optimization'],
                    ['content_optimization' => $subtotal]
                );

                if ($result['valid']) {
                    $applied_coupons[] = ['coupon' => $coupon, 'discount_amount' => $result['discount_amount']];
                    $current_amount    = round($current_amount - $result['discount_amount'], 2);
                }
            }
        }

        $total_coupon_discount = array_sum(array_column($applied_coupons, 'discount_amount'));
        $order_total           = round($subtotal - $total_coupon_discount, 2);

        $order = ContentOptimizationOrder::create([
            'user_id'                  => $user->id,
            'order_title'              => $order_title,
            'order_notes'              => $order_notes,
            'subtotal_before_discount' => $subtotal,
            'total_amount'             => $order_total,
            'status'                   => 'new_request',
            'payment_intent_id'        => $payment_method_id,
            'session_id'               => $session_id,
            'session_title'            => $session_title,
        ]);

        foreach ($items as $item_data) {
            $item_subtotal = round((float) $item_data['unit_price'] * (int) $item_data['quantity'], 2);

            $item = $order->items()->create([
                'tier_id'    => $item_data['tier_id'],
                'quantity'   => $item_data['quantity'],
                'unit_price' => (float) $item_data['unit_price'],
                'subtotal'   => $item_subtotal,
            ]);

            foreach ($item_data['intake_rows'] ?? [] as $index => $row) {
                $item->intakeRows()->create([
                    'row_index'          => $index + 1,
                    'primary_keyword'    => $row['primary_keyword'],
                    'secondary_keywords' => $row['secondary_keywords'] ?? null,
                    'content_page_url'   => $row['content_page_url'],
                    'notes'              => $row['notes'] ?? null,
                ]);
            }
        }

        $order->billing()->create([
            'company'     => $billing['company'] ?: null,
            'address'     => $billing['address'] ?: null,
            'city'        => $billing['city'] ?: null,
            'state'       => $billing['state'] ?: null,
            'country'     => $billing['country'] ?: null,
            'postal_code' => $billing['postal_code'] ?: null,
        ]);

        foreach ($applied_coupons as $entry) {
            $order->orderCoupons()->create([
                'coupon_id'       => $entry['coupon']->id,
                'discount_amount' => $entry['discount_amount'],
            ]);
        }

        $this->orderDetailsService->applyPaidStatus($order, $defer_details);

        return [
            'product_type' => 'content_optimization',
            'order_id'     => $order->id,
            'total_amount' => $order_total,
            'model'        => $order,
        ];
    }

    private function createNewContentOrder(
        User $user,
        array $items,
        array $billing,
        string $payment_method_id,
        array $coupon_models,
        ?string $order_title,
        ?string $order_notes,
        ?string $session_id,
        ?string $session_title,
        bool $skip_discounts = false,
        bool $defer_details = false
    ): array {
        $items = $this->tierPricingService->resolveItemPrices('new_content', $items);

        $subtotal = 0.0;

        foreach ($items as $item) {
            $subtotal += (float) $item['unit_price'] * (int) $item['quantity'];
        }

        $subtotal = round($subtotal, 2);

        $applied_coupons = [];
        $current_amount  = $subtotal;

        if (! $skip_discounts) {
            foreach ($coupon_models as $coupon) {
                $result = $this->couponService->validateAndCalculate(
                    $coupon,
                    $current_amount,
                    $user->id,
                    [],
                    [],
                    ['new_content'],
                    ['new_content' => $subtotal]
                );

                if ($result['valid']) {
                    $applied_coupons[] = ['coupon' => $coupon, 'discount_amount' => $result['discount_amount']];
                    $current_amount    = round($current_amount - $result['discount_amount'], 2);
                }
            }
        }

        $total_coupon_discount = array_sum(array_column($applied_coupons, 'discount_amount'));
        $order_total           = round($subtotal - $total_coupon_discount, 2);

        $order = NewContentOrder::create([
            'user_id'                  => $user->id,
            'order_title'              => $order_title,
            'order_notes'              => $order_notes,
            'subtotal_before_discount' => $subtotal,
            'total_amount'             => $order_total,
            'status'                   => 'new_request',
            'payment_intent_id'        => $payment_method_id,
            'session_id'               => $session_id,
            'session_title'            => $session_title,
        ]);

        foreach ($items as $item_data) {
            $item_subtotal = round((float) $item_data['unit_price'] * (int) $item_data['quantity'], 2);

            $item = $order->items()->create([
                'tier_id'    => $item_data['tier_id'],
                'quantity'   => $item_data['quantity'],
                'unit_price' => (float) $item_data['unit_price'],
                'subtotal'   => $item_subtotal,
            ]);

            foreach ($item_data['intake_rows'] ?? [] as $index => $row) {
                $item->intakeRows()->create([
                    'row_index'          => $index + 1,
                    'keyword_phrase'     => $row['keyword_phrase'],
                    'secondary_keywords' => ($row['secondary_keywords'] ?? null) ?: null,
                    'type_of_content'    => ($row['type_of_content'] ?? null) ?: null,
                    'notes'              => ($row['notes'] ?? null) ?: null,
                    'status'             => 'pending',
                ]);
            }
        }

        $order->billing()->create([
            'company'     => $billing['company'] ?: null,
            'address'     => $billing['address'] ?: null,
            'city'        => $billing['city'] ?: null,
            'state'       => $billing['state'] ?: null,
            'country'     => $billing['country'] ?: null,
            'postal_code' => $billing['postal_code'] ?: null,
        ]);

        foreach ($applied_coupons as $entry) {
            $order->orderCoupons()->create([
                'coupon_id'       => $entry['coupon']->id,
                'discount_amount' => $entry['discount_amount'],
            ]);
        }

        $this->orderDetailsService->applyPaidStatus($order, $defer_details);

        return [
            'product_type' => 'new_content',
            'order_id'     => $order->id,
            'total_amount' => $order_total,
            'model'        => $order,
        ];
    }

    /**
     * Dispatch all post-checkout payment notifications.
     * Non-critical: failures are logged but never roll back the purchase or fail the response.
     */
    private function dispatchCheckoutNotifications(Invoice $invoice, User $user): void
    {
        // Client payment confirmation email
        try {
            Mail::to($user->email)->queue(new PaymentSuccessfulEmail($user, $invoice));
        } catch (Throwable $e) {
            Log::warning('Failed to queue client payment confirmation email after checkout.', [
                'invoice_id' => $invoice->id,
                'user_id'    => $user->id,
                'error'      => $e->getMessage(),
            ]);
        }

        // Admin email notifications to all recipients in Email Notification Settings
        try {
            SendAdminInvoicePaidNotificationJob::dispatch($invoice->id);
        } catch (Throwable $e) {
            Log::warning('Failed to dispatch admin invoice paid notification job after checkout.', [
                'invoice_id' => $invoice->id,
                'error'      => $e->getMessage(),
            ]);
        }

        // Admin in-app notification (respecting Email Notification Settings) is
        // already dispatched by InvoiceService when it creates the "paid"
        // invoice above. Dispatching it again here would double-notify and
        // double-email every configured admin recipient.
    }

    private function createContentBriefOrder(
        User $user,
        array $items,
        array $billing,
        string $payment_method_id,
        array $coupon_models,
        ?string $order_title,
        ?string $order_notes,
        ?string $session_id,
        ?string $session_title,
        bool $skip_discounts = false,
        bool $defer_details = false
    ): array {
        $items = $this->tierPricingService->resolveItemPrices('content_brief', $items);

        $subtotal = 0.0;

        foreach ($items as $item) {
            $subtotal += (float) $item['unit_price'] * (int) $item['quantity'];
        }

        $subtotal = round($subtotal, 2);

        $applied_coupons = [];
        $current_amount  = $subtotal;

        if (! $skip_discounts) {
            foreach ($coupon_models as $coupon) {
                $result = $this->couponService->validateAndCalculate(
                    $coupon,
                    $current_amount,
                    $user->id,
                    [],
                    [],
                    ['content_brief'],
                    ['content_brief' => $subtotal]
                );

                if ($result['valid']) {
                    $applied_coupons[] = ['coupon' => $coupon, 'discount_amount' => $result['discount_amount']];
                    $current_amount    = round($current_amount - $result['discount_amount'], 2);
                }
            }
        }

        $total_coupon_discount = array_sum(array_column($applied_coupons, 'discount_amount'));
        $order_total           = round($subtotal - $total_coupon_discount, 2);

        $order = ContentBriefOrder::create([
            'user_id'                  => $user->id,
            'order_title'              => $order_title,
            'order_notes'              => $order_notes,
            'subtotal_before_discount' => $subtotal,
            'total_amount'             => $order_total,
            'status'                   => 'new_request',
            'payment_intent_id'        => $payment_method_id,
            'session_id'               => $session_id,
            'session_title'            => $session_title,
        ]);

        foreach ($items as $item_data) {
            $item_subtotal = round((float) $item_data['unit_price'] * (int) $item_data['quantity'], 2);

            $item = $order->items()->create([
                'tier_id'    => $item_data['tier_id'],
                'quantity'   => $item_data['quantity'],
                'unit_price' => (float) $item_data['unit_price'],
                'subtotal'   => $item_subtotal,
            ]);

            foreach ($item_data['intake_rows'] ?? [] as $index => $row) {
                $item->intakeRows()->create([
                    'row_index'          => $index + 1,
                    'primary_keyword'    => $row['primary_keyword'],
                    'secondary_keywords' => $row['secondary_keywords'] ?? null,
                    'content_page_url'   => $row['content_page_url'],
                    'notes'              => $row['notes'] ?? null,
                ]);
            }
        }

        $order->billing()->create([
            'company'     => $billing['company'] ?: null,
            'address'     => $billing['address'] ?: null,
            'city'        => $billing['city'] ?: null,
            'state'       => $billing['state'] ?: null,
            'country'     => $billing['country'] ?: null,
            'postal_code' => $billing['postal_code'] ?: null,
        ]);

        foreach ($applied_coupons as $entry) {
            $order->orderCoupons()->create([
                'coupon_id'       => $entry['coupon']->id,
                'discount_amount' => $entry['discount_amount'],
            ]);
        }

        $this->orderDetailsService->applyPaidStatus($order, $defer_details);

        return [
            'product_type' => 'content_brief',
            'order_id'     => $order->id,
            'total_amount' => $order_total,
            'model'        => $order,
        ];
    }
}
