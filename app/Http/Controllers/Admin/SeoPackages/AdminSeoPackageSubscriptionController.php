<?php

namespace App\Http\Controllers\Admin\SeoPackages;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SeoPackages\CancelAdminSeoPackageSubscriptionRequest;
use App\Http\Requests\Admin\SeoPackages\ChargeAdminSeoPackageSubscriptionRequest;
use App\Http\Requests\Admin\SeoPackages\StoreAdminSeoPackageSubscriptionRequest;
use App\Http\Requests\Admin\SeoPackages\UpdateAdminSeoPackageSubscriptionRequest;
use App\Http\Resources\AdminSeoPackageSubscriptionResource;
use App\Http\Resources\AdminSeoSubscriptionChargeResource;
use App\Models\PaymentProfile;
use App\Models\SeoPackageSubscription;
use App\Models\SeoSubscriptionCharge;
use App\Models\User;
use App\Services\InvoiceCardPaymentService;
use App\Services\SeoSubscriptionBillingService;
use App\Services\SeoSubscriptionPaymentHealthService;
use App\Support\BillingCalendar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminSeoPackageSubscriptionController extends Controller
{
    private array $eagerLoad = [
        'user:id,first_name,last_name,email,company,organization_id',
        'user.organization:id,name',
        'package:id,name,slug,price_per_month',
        'paymentProfile:id,user_id,card_brand,last_four,expiry_month,expiry_year',
        'latestCharge.invoice:id,unique_id,invoice_number,status',
    ];

    public function __construct(
        protected SeoSubscriptionBillingService $billing_service,
        protected SeoSubscriptionPaymentHealthService $health_service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = SeoPackageSubscription::with($this->eagerLoad);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('user', function ($user_q) use ($search) {
                    $user_q->where('first_name', 'like', "%{$search}%")
                           ->orWhere('last_name', 'like', "%{$search}%")
                           ->orWhere('email', 'like', "%{$search}%")
                           ->orWhere('company', 'like', "%{$search}%");
                })->orWhereHas('package', function ($pkg_q) use ($search) {
                    $pkg_q->where('name', 'like', "%{$search}%");
                })->orWhere('plan_name', 'like', "%{$search}%");
            });
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($package_id = $request->query('package_id')) {
            $query->where('seo_package_id', $package_id);
        }

        if ($pricing_type = $request->query('pricing_type')) {
            $query->where('pricing_type', $pricing_type);
        }

        match ($request->query('payment_status')) {
            // Declined renewals.
            SeoPackageSubscription::PAYMENT_STATUS_PAST_DUE => $query->where('payment_status', SeoPackageSubscription::PAYMENT_STATUS_PAST_DUE),
            // Still active, a billing date passed and nothing was charged.
            'pending'   => $this->wherePaymentPending($query),
            // Either of the two: everything an admin should look at.
            'attention' => $query->where(fn ($q) => $q
                ->where(fn ($past_due) => $past_due->where('status', 'active')->where('payment_status', SeoPackageSubscription::PAYMENT_STATUS_PAST_DUE))
                ->orWhere(fn ($pending) => $this->wherePaymentPending($pending))),
            default     => null,
        };

        if ($request->has('auto_renew') && $request->query('auto_renew') !== '') {
            $query->where('auto_renew', filter_var($request->query('auto_renew'), FILTER_VALIDATE_BOOLEAN));
        }

        $allowed_sort_fields = ['starts_at', 'ends_at', 'created_at', 'status', 'monthly_price', 'next_billing_date'];
        $sort_field          = in_array($request->query('sort_field'), $allowed_sort_fields)
            ? $request->query('sort_field')
            : 'created_at';
        $sort_direction = $request->query('sort_direction', 'desc') === 'asc' ? 'asc' : 'desc';

        $query->orderBy("seo_package_subscriptions.{$sort_field}", $sort_direction);

        $per_page  = min((int) $request->query('per_page', 20), 100);
        $paginated = $query->paginate($per_page);

        // One card query for the whole page instead of one per row.
        $this->health_service->primeCardStatus(collect($paginated->items())->pluck('user_id'));

        return response()->json([
            'data' => [
                'data'         => AdminSeoPackageSubscriptionResource::collection($paginated->items()),
                'total'        => $paginated->total(),
                'last_page'    => $paginated->lastPage(),
                'current_page' => $paginated->currentPage(),
                'per_page'     => $paginated->perPage(),
            ],
        ]);
    }

    public function stats(): JsonResponse
    {
        $total     = SeoPackageSubscription::count();
        $by_status = SeoPackageSubscription::selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $active          = SeoPackageSubscription::where('status', 'active');
        $past_due        = (clone $active)->where('payment_status', SeoPackageSubscription::PAYMENT_STATUS_PAST_DUE)->count();
        $payment_pending = $this->wherePaymentPending(SeoPackageSubscription::query())->count();

        return response()->json([
            'data' => [
                'total'                 => $total,
                'active'                => (int) ($by_status['active']    ?? 0),
                'cancelled'             => (int) ($by_status['cancelled'] ?? 0),
                'expired'               => (int) ($by_status['expired']   ?? 0),
                'past_due'              => $past_due,
                'payment_pending'       => $payment_pending,
                'needs_attention'       => $past_due + $payment_pending,
                'auto_renew'            => (clone $active)->where('auto_renew', true)->count(),
                'monthly_recurring'     => round((float) (clone $active)->sum('monthly_price'), 2),
                'renewing_next_7_days'  => (clone $active)
                    ->where('auto_renew', true)
                    ->whereDate('next_billing_date', '>=', BillingCalendar::todayString())
                    ->whereDate('next_billing_date', '<=', BillingCalendar::today()->addDays(7)->toDateString())
                    ->count(),
                'collected_this_month'  => round((float) SeoSubscriptionCharge::where('status', SeoSubscriptionCharge::STATUS_SUCCEEDED)
                    ->where('paid_at', '>=', now()->startOfMonth())
                    ->sum('amount'), 2),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $subscription = SeoPackageSubscription::find($id);

        if (! $subscription) {
            return response()->json(['message' => 'Subscription not found.'], 404);
        }

        // Picks up invoices paid from the portal since the last renewal run.
        $this->billing_service->syncExternalPayments($subscription);

        return $this->detailResponse($subscription);
    }

    /**
     * GET /api/admin/seo-packages/subscriptions/payment-profiles?user_id=
     *
     * Cards on file for a client, for the activate and edit forms.
     */
    public function paymentProfiles(Request $request, InvoiceCardPaymentService $card_payment_service): JsonResponse
    {
        $user = User::find((int) $request->query('user_id'));

        if (! $user) {
            return response()->json(['data' => []]);
        }

        return response()->json(['data' => $card_payment_service->listPaymentProfiles($user)]);
    }

    /**
     * GET /api/admin/seo-packages/subscriptions/{id}/payment-profiles
     *
     * Cards on file for the owner of this subscription only, used by the
     * manual checkout page. The client is resolved on the server from the
     * subscription, so the page can never list another client's cards.
     */
    public function subscriptionPaymentProfiles(int $id, InvoiceCardPaymentService $card_payment_service): JsonResponse
    {
        $subscription = SeoPackageSubscription::with('user')->find($id);

        if (! $subscription || ! $subscription->user) {
            return response()->json(['message' => 'Subscription not found.'], 404);
        }

        return response()->json([
            'data' => $card_payment_service->listPaymentProfiles($subscription->user),
            'meta' => [
                'subscription_id' => $subscription->id,
                'user_id'         => $subscription->user_id,
            ],
        ]);
    }

    public function store(StoreAdminSeoPackageSubscriptionRequest $request): JsonResponse
    {
        $data         = $request->validated();
        $subscription = $this->billing_service->activate($data);
        $charge       = null;

        if ($request->boolean('first_period_paid_outside')) {
            // Already paid another way: recorded as paid, never charged.
            $paid_result = $this->billing_service->recordPeriodPaidOutside(
                $subscription,
                Auth::user(),
                $request->input('outside_payment_reference'),
            );

            $charge = [
                'success'      => $paid_result['success'],
                'message'      => $paid_result['message'],
                'paid_outside' => true,
            ];
        } elseif ($request->boolean('charge_now')) {
            $charge_result = $this->billing_service->chargeCurrentPeriod(
                $subscription,
                SeoSubscriptionCharge::TRIGGER_MANUAL,
                Auth::user(),
            );

            $charge = [
                'success'      => $charge_result['success'],
                'message'      => $charge_result['message'],
                'paid_outside' => false,
            ];
        }

        $subscription->refresh()->load($this->eagerLoad);

        return response()->json([
            'data'          => new AdminSeoPackageSubscriptionResource($subscription),
            'charge_result' => $charge,
        ], 201);
    }

    public function update(UpdateAdminSeoPackageSubscriptionRequest $request, int $id): JsonResponse
    {
        $subscription = SeoPackageSubscription::find($id);

        if (! $subscription) {
            return response()->json(['message' => 'Subscription not found.'], 404);
        }

        if (! $subscription->isActive()) {
            return response()->json(['message' => 'Only active subscriptions can be edited.'], 422);
        }

        $this->billing_service->updateSettings($subscription, $request->validated());

        return $this->detailResponse($subscription);
    }

    /**
     * POST /api/admin/seo-packages/subscriptions/{id}/charge
     *
     * Bills the current period now with the card on file. Every failure
     * response carries an `error_code` so the checkout page can explain
     * the problem (insufficient funds, expired card, and so on).
     */
    public function charge(
        ChargeAdminSeoPackageSubscriptionRequest $request,
        int $id,
        InvoiceCardPaymentService $card_payment_service,
    ): JsonResponse {
        $subscription = SeoPackageSubscription::find($id);

        if (! $subscription) {
            return $this->chargeError('Subscription not found.', 'subscription_not_found', 404);
        }

        $payment_profile = null;

        if ($request->filled('payment_profile_id')) {
            // Scoped to the subscription owner: a card that belongs to any
            // other user is treated as if it did not exist.
            $payment_profile = PaymentProfile::where('id', $request->input('payment_profile_id'))
                ->where('user_id', $subscription->user_id)
                ->first();

            if (! $payment_profile) {
                return $this->chargeError('Saved card not found for this client.', 'card_not_found', 404);
            }

            // Rejected before any attempt, so no failed charge is recorded
            // and the subscription is not flagged as past due.
            if ($card_payment_service->isPaymentProfileExpired($payment_profile)) {
                return $this->chargeError(
                    'This card has expired. Choose another card or ask the client to add a new one.',
                    'expired_card',
                    422,
                );
            }
        }

        $result = $this->billing_service->chargeCurrentPeriod(
            $subscription,
            SeoSubscriptionCharge::TRIGGER_MANUAL,
            Auth::user(),
            $payment_profile,
        );

        $charge = $result['charge']?->load('invoice:id,unique_id,invoice_number,status,total_amount');

        if (! $result['success']) {
            return $this->chargeError(
                $result['message'],
                $charge?->isSucceeded()
                    ? 'already_paid'
                    : ($charge?->failure_code ?? $this->errorCodeForStatus($result['status_code'])),
                $result['status_code'],
                $charge,
            );
        }

        return $this->detailResponse($subscription, $result['message'], $charge);
    }

    private function chargeError(string $message, string $error_code, int $status_code, ?SeoSubscriptionCharge $charge = null): JsonResponse
    {
        return response()->json([
            'message'    => $message,
            'error_code' => $error_code,
            'charge'     => $charge ? new AdminSeoSubscriptionChargeResource($charge) : null,
        ], $status_code);
    }

    private function errorCodeForStatus(int $status_code): string
    {
        return match ($status_code) {
            409     => 'charge_conflict',
            422     => 'charge_not_allowed',
            default => 'card_declined',
        };
    }

    public function cancel(CancelAdminSeoPackageSubscriptionRequest $request, int $id): JsonResponse
    {
        $subscription = SeoPackageSubscription::with($this->eagerLoad)->find($id);

        if (! $subscription) {
            return response()->json(['message' => 'Subscription not found.'], 404);
        }

        if ($subscription->status !== 'active') {
            return response()->json([
                'message' => 'Only active subscriptions can be cancelled.',
                'errors'  => [
                    'status' => ['This subscription is already cancelled or expired.'],
                ],
            ], 422);
        }

        $this->billing_service->cancel(
            $subscription,
            $request->input('notes'),
            $request->boolean('void_open_invoices'),
            Auth::user(),
        );

        return response()->json(['data' => new AdminSeoPackageSubscriptionResource($subscription->load($this->eagerLoad))]);
    }

    private function detailResponse(
        SeoPackageSubscription $subscription,
        ?string $message = null,
        ?SeoSubscriptionCharge $charge = null,
    ): JsonResponse
    {
        $subscription->refresh()->load([
            ...$this->eagerLoad,
            'charges' => fn ($q) => $q->with(['invoice:id,unique_id,invoice_number,status,total_amount', 'chargedBy:id,first_name,last_name,email'])
                ->orderByDesc('period_start'),
        ]);

        $charges = $subscription->charges;

        return response()->json(array_filter([
            'data'    => new AdminSeoPackageSubscriptionResource($subscription),
            'summary' => [
                'total_collected'  => round((float) $charges->where('status', SeoSubscriptionCharge::STATUS_SUCCEEDED)->sum('amount'), 2),
                'successful_count' => $charges->where('status', SeoSubscriptionCharge::STATUS_SUCCEEDED)->count(),
                'failed_count'     => $charges->where('status', SeoSubscriptionCharge::STATUS_FAILED)->count(),
                'upcoming'         => $this->billing_service->upcomingSchedule($subscription),
                'card_on_file'     => (bool) $this->billing_service->resolvePaymentProfile($subscription),
                // Unpaid invoices the admin can keep open or void when cancelling.
                'open_invoices'    => $this->billing_service->openInvoices($subscription)
                    ->map(fn ($invoice) => [
                        'id'             => $invoice->id,
                        'unique_id'      => $invoice->unique_id,
                        'invoice_number' => $invoice->invoice_number,
                        'status'         => $invoice->status,
                        'total_amount'   => (float) $invoice->total_amount,
                    ])
                    ->values(),
            ],
            'message' => $message,
            'charge'  => $charge ? new AdminSeoSubscriptionChargeResource($charge) : null,
        ], fn ($value) => $value !== null));
    }

    /**
     * Active, not flagged past due, but a billing date already passed
     * without a payment: manual billing nobody charged, or a renewal that was
     * never attempted. Shown to admins as "Payment pending".
     */
    private function wherePaymentPending($query)
    {
        return $query
            ->where('status', 'active')
            ->where('payment_status', SeoPackageSubscription::PAYMENT_STATUS_CURRENT)
            ->whereNotNull('next_billing_date')
            ->whereDate('next_billing_date', '<', BillingCalendar::todayString())
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhereColumn('next_billing_date', '<=', 'ends_at'));
    }
}
