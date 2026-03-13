<?php

namespace App\Http\Controllers\LinkBuilding;

use App\Events\LinkBuildingOrderPlaced;
use App\Http\Controllers\Controller;
use App\Http\Requests\LinkBuilding\StoreLinkBuildingOrderRequest;
use App\Models\LinkBuildingOrder;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\StripeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Stripe\Exception\ApiErrorException;

class LinkBuildingOrderController extends Controller
{
    private const BULK_DISCOUNT_THRESHOLD = 10;
    private const BULK_DISCOUNT_RATE      = 0.10;

    public function __construct(
        protected InvoiceService $invoiceService,
        protected StripeService $stripeService
    ) {}

    public function index(): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $orders = LinkBuildingOrder::where('user_id', $user->id)
            ->withCount(['items as items_count' => function ($query) {
                $query->selectRaw('sum(quantity)');
            }])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn ($order) => [
                'id'           => $order->id,
                'order_title'  => $order->order_title,
                'total_amount' => $order->total_amount,
                'status'       => $order->status,
                'created_at'   => $order->created_at,
                'items_count'  => (int) ($order->items_count ?? 0),
            ]);

        return response()->json(['data' => $orders]);
    }

    public function store(StoreLinkBuildingOrderRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $total_links = collect($request->items)->sum('quantity');
        $subtotal    = collect($request->items)->sum(fn ($item) => $item['unit_price'] * $item['quantity']);

        $discount_applied = $total_links >= self::BULK_DISCOUNT_THRESHOLD;
        $total_amount     = $discount_applied
            ? round($subtotal * (1 - self::BULK_DISCOUNT_RATE), 2)
            : round($subtotal, 2);

        $order = DB::transaction(function () use ($request, $user, $total_amount) {
            $order = LinkBuildingOrder::create([
                'user_id'      => $user->id,
                'order_title'  => $request->order_title,
                'order_notes'  => $request->order_notes,
                'total_amount' => $total_amount,
                'status'       => 'pending',
            ]);

            foreach ($request->items as $item_data) {
                $subtotal = round($item_data['unit_price'] * $item_data['quantity'], 2);

                $item = $order->items()->create([
                    'dr_tier_id' => $item_data['dr_tier_id'],
                    'quantity'   => $item_data['quantity'],
                    'unit_price' => $item_data['unit_price'],
                    'subtotal'   => $subtotal,
                ]);

                foreach ($item_data['placements'] as $placement_data) {
                    $item->placements()->create([
                        'row_index'    => $placement_data['row_index'],
                        'keyword'      => $placement_data['keyword'] ?: null,
                        'landing_page' => $placement_data['landing_page'] ?: null,
                        'exact_match'  => $placement_data['exact_match'],
                    ]);
                }
            }

            $order->billing()->create([
                'company'     => $request->billing['company'] ?? null,
                'address'     => $request->billing['address'],
                'city'        => $request->billing['city'],
                'state'       => $request->billing['state'],
                'country'     => $request->billing['country'],
                'postal_code' => $request->billing['postal_code'],
            ]);

            return $order;
        });

        $payment_result = $this->processOrderPayment($user, $order, $request->payment, $total_amount);

        if (isset($payment_result['error'])) {
            $order->delete();
            return response()->json(['message' => $payment_result['error']], 422);
        }

        $invoice = $this->invoiceService->createForLinkBuildingOrder(
            user: $user,
            order: $order,
            payment_method: $payment_result['payment_method_label'],
            stripe_payment_intent_id: $payment_result['stripe_payment_intent_id'] ?? null,
            stripe_charge_id: $payment_result['stripe_charge_id'] ?? null,
            initial_status: $payment_result['invoice_status'],
        );

        event(new LinkBuildingOrderPlaced($user, $order, $total_links));

        $response = [
            'order_id'         => $order->id,
            'status'           => $order->status,
            'total_amount'     => $order->total_amount,
            'discount_applied' => $discount_applied,
            'created_at'       => $order->created_at,
            'invoice_number'   => $invoice->invoice_number,
            'invoice_id'       => $invoice->unique_id,
            'payment_status'   => $payment_result['invoice_status'],
        ];

        // 3D Secure required — return client_secret so frontend can complete authentication
        if ($payment_result['invoice_status'] === 'pending') {
            $response['requires_action'] = true;
            $response['client_secret']   = $payment_result['client_secret'];
        }

        return response()->json(['data' => $response], 201);
    }

    public function show(string $id): JsonResponse
    {
        $user = auth()->user();

        $order = LinkBuildingOrder::where('id', $id)
            ->where('user_id', $user->id)
            ->with([
                'items.drTier',
                'items.placements',
                'billing',
                'invoice.lineItems',
                'invoice.billedTo',
            ])
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        return response()->json(['data' => $this->buildOrderDetail($order)]);
    }

    /**
     * Resolve payment for an order based on the payment type provided.
     *
     * Returns an array with:
     *   payment_method_label     string   — human-readable label stored on the invoice
     *   invoice_status           string   — 'paid' or 'pending' (pending = 3DS required)
     *   stripe_payment_intent_id string|null
     *   stripe_charge_id         string|null
     *   client_secret            string|null  — only present when requires_action
     *   error                    string|null  — present when payment failed
     */
    private function processOrderPayment(
        User $user,
        LinkBuildingOrder $order,
        array $payment,
        float $total_amount
    ): array {
        $type = $payment['type'];

        if ($type === 'account_balance') {
            return [
                'payment_method_label'     => 'Account Balance',
                'invoice_status'           => 'paid',
                'stripe_payment_intent_id' => null,
                'stripe_charge_id'         => null,
                'client_secret'            => null,
                'error'                    => null,
            ];
        }

        if ($type === 'credits') {
            return [
                'payment_method_label'     => 'Credits',
                'invoice_status'           => 'paid',
                'stripe_payment_intent_id' => null,
                'stripe_charge_id'         => null,
                'client_secret'            => null,
                'error'                    => null,
            ];
        }

        // Determine the Stripe PaymentMethod ID to charge
        $stripe_pm_id  = null;
        $off_session   = false;

        if ($type === 'saved_card') {
            $profile = PaymentMethod::where('id', $payment['payment_profile_id'])
                ->where('user_id', $user->id)
                ->first();

            if (!$profile) {
                return ['error' => 'Payment profile not found.', 'payment_method_label' => '', 'invoice_status' => '', 'stripe_payment_intent_id' => null, 'stripe_charge_id' => null, 'client_secret' => null];
            }

            $stripe_pm_id = $profile->stripe_payment_method_id;
            $off_session  = true;
        }

        if ($type === 'new_card') {
            $stripe_pm_id = $payment['stripe_payment_method_id'];

            // Optionally save the card to the user's billing profile
            if (!empty($payment['save_card'])) {
                try {
                    $this->stripeService->attachPaymentMethod($user, $stripe_pm_id, false);
                } catch (ApiErrorException) {
                    // Non-fatal: card save failed, payment can still proceed
                }
            }
        }

        try {
            $amount_cents = (int) round($total_amount * 100);
            $description  = 'Link Building Order #' . $order->id;

            $result = $this->stripeService->processPayment(
                user: $user,
                amount_cents: $amount_cents,
                stripe_payment_method_id: $stripe_pm_id,
                currency: 'usd',
                description: $description,
                off_session: $off_session
            );

            if ($result['status'] === 'failed') {
                return ['error' => $result['error'] ?? 'Payment was declined.', 'payment_method_label' => '', 'invoice_status' => '', 'stripe_payment_intent_id' => null, 'stripe_charge_id' => null, 'client_secret' => null];
            }

            return [
                'payment_method_label'     => 'Credit Card',
                'invoice_status'           => $result['status'] === 'succeeded' ? 'paid' : 'pending',
                'stripe_payment_intent_id' => $result['payment_intent_id'],
                'stripe_charge_id'         => $result['charge_id'],
                'client_secret'            => $result['client_secret'],
                'error'                    => null,
            ];
        } catch (ApiErrorException $e) {
            return ['error' => $e->getMessage(), 'payment_method_label' => '', 'invoice_status' => '', 'stripe_payment_intent_id' => null, 'stripe_charge_id' => null, 'client_secret' => null];
        }
    }

    private function buildOrderDetail(LinkBuildingOrder $order): array
    {
        $invoice = $order->invoice;

        return [
            'id'              => $order->id,
            'order_title'     => $order->order_title,
            'order_notes'     => $order->order_notes,
            'status'          => $order->status,
            'total_amount'    => $order->total_amount,
            'created_at'      => $order->created_at?->format('F j, Y'),
            'billing'         => $order->billing ? [
                'company'     => $order->billing->company,
                'address'     => $order->billing->address,
                'city'        => $order->billing->city,
                'state'       => $order->billing->state,
                'country'     => $order->billing->country,
                'postal_code' => $order->billing->postal_code,
            ] : null,
            'items'   => $order->items->map(fn ($item) => [
                'id'         => $item->id,
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
            'invoice' => $invoice ? [
                'unique_id'       => $invoice->unique_id,
                'invoice_number'  => $invoice->invoice_number,
                'status'          => $invoice->status,
                'payment_method'  => $invoice->payment_method,
                'currency_type'   => $invoice->currency_type,
                'subtotal_amount' => $invoice->subtotal_amount,
                'total_amount'    => $invoice->total_amount,
                'credit_amount'   => $invoice->credit_amount,
                'date_issued'     => $invoice->date_issued?->format('F j, Y'),
                'date_due'        => $invoice->date_due?->format('F j, Y'),
                'date_paid'       => $invoice->date_paid?->format('F j, Y'),
                'billed_to'       => $invoice->billedTo ? [
                    'company_name'        => $invoice->billedTo->company_name,
                    'company_description' => $invoice->billedTo->company_description,
                    'address_line_1'      => $invoice->billedTo->address_line_1,
                    'address_line_2'      => $invoice->billedTo->address_line_2,
                    'state'               => $invoice->billedTo->state,
                    'country'             => $invoice->billedTo->country,
                ] : null,
                'line_items' => $invoice->lineItems->map(fn ($li) => [
                    'item_name'  => $li->item_name,
                    'price'      => $li->price,
                    'quantity'   => $li->quantity,
                    'item_total' => $li->item_total,
                ]),
            ] : null,
        ];
    }
}
