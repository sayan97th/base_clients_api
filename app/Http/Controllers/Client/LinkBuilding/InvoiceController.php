<?php

namespace App\Http\Controllers\Client\LinkBuilding;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\Invoice\PayInvoiceRequest;
use App\Models\ContentBriefOrder;
use App\Models\ContentOptimizationOrder;
use App\Models\Invoice;
use App\Models\InvoiceHistory;
use App\Models\LinkBuildingOrder;
use App\Models\NewContentOrder;
use App\Models\User;
use App\Notifications\InvoiceCreatedNotification;
use App\Services\InvoiceService;
use App\Services\StripeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InvoiceController extends Controller
{
    public function __construct(
        protected InvoiceService $invoiceService,
        protected StripeService $stripeService,
    ) {}

    /**
     * GET /api/invoices
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'page'     => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search'   => ['nullable', 'string', 'max:255'],
            'status'   => ['nullable', 'string', Rule::in(Invoice::STATUSES)],
        ]);

        /** @var User $user */
        $user     = auth()->user();
        $per_page = min((int) $request->input('per_page', 10), 100);
        $search   = $request->input('search');
        $status   = $request->input('status');

        $query = Invoice::where('user_id', $user->id)
            ->with('lineItems')
            ->orderBy('date_issued', 'desc');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('unique_id', 'like', "%{$search}%")
                  ->orWhere('invoice_number', 'like', "%{$search}%")
                  ->orWhere('status', 'like', "%{$search}%")
                  ->orWhereRaw("DATE_FORMAT(date_issued, '%M %d, %Y') LIKE ?", ["%{$search}%"]);
            });
        }

        if ($status) {
            $query->where('status', $status);
        }

        $paginator = $query->paginate($per_page);

        $data = collect($paginator->items())->map(fn (Invoice $invoice) => [
            'unique_id'     => $invoice->unique_id,
            'date'          => $invoice->date_issued?->format('M j, Y'),
            'date_due'      => $invoice->date_due?->format('M j, Y'),
            'total'         => $this->formatAmount($invoice->total_amount, $invoice->currency_type),
            'status'        => $invoice->status,
            'product_types' => $invoice->lineItems
                ->pluck('product_type')
                ->filter()
                ->unique()
                ->values()
                ->all(),
        ]);

        return response()->json([
            'data'         => $data,
            'current_page' => $paginator->currentPage(),
            'last_page'    => $paginator->lastPage(),
            'per_page'     => $paginator->perPage(),
            'total'        => $paginator->total(),
        ]);
    }

    /**
     * GET /api/invoices/{unique_id}
     */
    public function show(string $unique_id): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

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

        return response()->json(['data' => $this->buildInvoiceDetail($invoice)]);
    }

    /**
     * POST /api/invoices
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'order_id'       => ['required', 'uuid'],
            'payment_method' => ['nullable', 'string', Rule::in(Invoice::PAYMENT_METHODS)],
            'currency_type'  => ['nullable', 'string', Rule::in(Invoice::CURRENCY_TYPES)],
            'credit_amount'  => ['nullable', 'numeric', 'min:0'],
        ]);

        /** @var User $user */
        $user           = auth()->user();
        $order_id       = $request->input('order_id');
        $payment_method = $request->input('payment_method', 'Account Balance');
        $currency_type  = $request->input('currency_type', 'usd');
        $credit_amount  = (float) $request->input('credit_amount', 0);

        [$order, $product_type] = $this->resolveOrder($order_id, $user->id);

        if (! $order) {
            $any_order = $this->resolveOrderWithoutUser($order_id);

            return $any_order
                ? response()->json(['message' => 'This order does not belong to your account.'], 403)
                : response()->json(['message' => 'Order not found.'], 404);
        }

        $existing = Invoice::where('order_id', $order->id)->first();

        if ($existing) {
            return response()->json(['message' => 'An invoice already exists for this order.'], 409);
        }

        $invoice = match ($product_type) {
            'link_building' => $this->invoiceService->createForLinkBuildingOrder(
                $user, $order, $payment_method, $currency_type, $credit_amount
            ),
            'new_content' => $this->invoiceService->createForNewContentOrder(
                $user, $order, $payment_method, $currency_type, $credit_amount
            ),
            'content_optimization' => $this->invoiceService->createForContentOptimizationOrder(
                $user, $order, $payment_method, $currency_type, $credit_amount
            ),
            'content_brief' => $this->invoiceService->createForContentBriefOrder(
                $user, $order, $payment_method, $currency_type, $credit_amount
            ),
        };

        $invoice->loadMissing(['lineItems', 'billedTo', 'couponDiscounts']);

        return response()->json(['data' => $this->buildInvoiceDetail($invoice)], 201);
    }

    /**
     * POST /api/invoices/{unique_id}/pay
     */
    public function pay(PayInvoiceRequest $request, string $unique_id): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

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

        if ($invoice->status === 'paid') {
            return response()->json(['message' => 'This invoice has already been paid.'], 409);
        }

        if (! in_array($invoice->status, ['unpaid', 'overdue'], true)) {
            return response()->json(['message' => 'This invoice cannot be paid in its current status.'], 409);
        }

        $payment_method = $request->input('payment_method');

        if ($payment_method === 'account_balance') {
            $result = $this->payViaAccountBalance($invoice, $user);
        } else {
            $result = $this->payViaCreditCard($invoice, $user, $request->input('payment_intent_id'));
        }

        if (! $result['success']) {
            return response()->json(['message' => $result['message']], $result['status_code'] ?? 422);
        }

        $invoice->refresh()->load(['lineItems', 'billedTo', 'couponDiscounts']);

        return response()->json([
            'message' => 'Invoice paid successfully.',
            'data'    => $this->buildInvoiceDetail($invoice),
        ]);
    }

    /**
     * POST /api/invoices/{unique_id}/send-notification
     */
    public function sendNotification(string $unique_id): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $invoice = Invoice::where('unique_id', $unique_id)
            ->where('user_id', $user->id)
            ->with('lineItems')
            ->first();

        if (! $invoice) {
            $exists = Invoice::where('unique_id', $unique_id)->exists();

            return $exists
                ? response()->json(['message' => 'This invoice does not belong to your account.'], 403)
                : response()->json(['message' => 'Invoice not found.'], 404);
        }

        $user->notify(new InvoiceCreatedNotification($invoice, $user));

        InvoiceHistory::create([
            'invoice_id'     => $invoice->id,
            'event'          => 'notification resent',
            'description'    => 'Invoice notification resent at client request.',
            'actor_id'       => $user->id,
            'actor_name'     => $user->full_name ?? $user->email,
            'actor_initials' => $this->buildInitials($user->full_name ?? $user->email),
            'actor_type'     => 'client',
        ]);

        return response()->json(null, 204);
    }

    private function payViaAccountBalance(Invoice $invoice, User $user): array
    {
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

        return ['success' => true];
    }

    private function payViaCreditCard(Invoice $invoice, User $user, string $payment_intent_id): array
    {
        $verify_result = $this->stripeService->verifyPaymentIntent($payment_intent_id);

        if (! $verify_result['verified']) {
            return [
                'success'     => false,
                'message'     => $verify_result['message'],
                'status_code' => 402,
            ];
        }

        $invoice->status         = 'paid';
        $invoice->date_paid      = now();
        $invoice->payment_method = 'Credit Card';
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

        return ['success' => true];
    }

    private function resolveOrder(string $order_id, int|string $user_id): array
    {
        $order = LinkBuildingOrder::where('id', $order_id)->where('user_id', $user_id)->first();
        if ($order) {
            return [$order, 'link_building'];
        }

        $order = NewContentOrder::where('id', $order_id)->where('user_id', $user_id)->first();
        if ($order) {
            return [$order, 'new_content'];
        }

        $order = ContentOptimizationOrder::where('id', $order_id)->where('user_id', $user_id)->first();
        if ($order) {
            return [$order, 'content_optimization'];
        }

        $order = ContentBriefOrder::where('id', $order_id)->where('user_id', $user_id)->first();
        if ($order) {
            return [$order, 'content_brief'];
        }

        return [null, null];
    }

    private function resolveOrderWithoutUser(string $order_id): bool
    {
        return LinkBuildingOrder::where('id', $order_id)->exists()
            || NewContentOrder::where('id', $order_id)->exists()
            || ContentOptimizationOrder::where('id', $order_id)->exists()
            || ContentBriefOrder::where('id', $order_id)->exists();
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
            'invoice_number' => $invoice->invoice_number,
            'unique_id'      => $invoice->unique_id,
            'date_issued'    => $invoice->date_issued?->format('M j, Y'),
            'date_paid'      => $invoice->date_paid?->format('M j, Y'),
            'date_due'       => $invoice->date_due?->format('M j, Y'),
            'payment_method' => $invoice->payment_method,
            'status'         => $invoice->status,
            'subtotal'       => $this->formatAmount($invoice->subtotal_amount, $invoice->currency_type),
            'discount'       => $bulk_discount > 0 ? $this->formatAmount($bulk_discount, $invoice->currency_type) : null,
            'discount_type'  => $invoice->discount_type,
            'total'          => $this->formatAmount($invoice->total_amount, $invoice->currency_type),
            'credit'         => $this->formatCredit($invoice->credit_amount, $invoice->currency_type),
            'notes'          => $invoice->notes,
            'billed_to'      => $billed_to ? [
                'company_name'        => $billed_to->company_name,
                'company_description' => $billed_to->company_description,
                'address_line_1'      => $billed_to->address_line_1,
                'address_line_2'      => $billed_to->address_line_2,
                'state'               => $billed_to->state,
                'country'             => $billed_to->country,
            ] : null,
            'line_items' => $invoice->lineItems->map(fn ($item) => [
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
