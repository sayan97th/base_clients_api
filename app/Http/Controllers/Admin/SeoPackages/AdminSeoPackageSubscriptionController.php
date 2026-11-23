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
        'latestCharge',
    ];

    public function __construct(
        protected SeoSubscriptionBillingService $billing_service,
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

        if ($request->query('payment_status') === SeoPackageSubscription::PAYMENT_STATUS_PAST_DUE) {
            $query->where('payment_status', SeoPackageSubscription::PAYMENT_STATUS_PAST_DUE);
        }

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

        $active = SeoPackageSubscription::where('status', 'active');

        return response()->json([
            'data' => [
                'total'                 => $total,
                'active'                => (int) ($by_status['active']    ?? 0),
                'cancelled'             => (int) ($by_status['cancelled'] ?? 0),
                'expired'               => (int) ($by_status['expired']   ?? 0),
                'past_due'              => (clone $active)->where('payment_status', SeoPackageSubscription::PAYMENT_STATUS_PAST_DUE)->count(),
                'auto_renew'            => (clone $active)->where('auto_renew', true)->count(),
                'monthly_recurring'     => round((float) (clone $active)->sum('monthly_price'), 2),
                'renewing_next_7_days'  => (clone $active)
                    ->where('auto_renew', true)
                    ->whereDate('next_billing_date', '>=', now()->toDateString())
                    ->whereDate('next_billing_date', '<=', now()->addDays(7)->toDateString())
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

    public function store(StoreAdminSeoPackageSubscriptionRequest $request): JsonResponse
    {
        $data         = $request->validated();
        $subscription = $this->billing_service->activate($data);
        $charge       = null;

        if ($request->boolean('charge_now')) {
            $charge_result = $this->billing_service->chargeCurrentPeriod(
                $subscription,
                SeoSubscriptionCharge::TRIGGER_MANUAL,
                Auth::user(),
            );

            $charge = [
                'success' => $charge_result['success'],
                'message' => $charge_result['message'],
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
     * Bills the current period now with the card on file.
     */
    public function charge(ChargeAdminSeoPackageSubscriptionRequest $request, int $id): JsonResponse
    {
        $subscription = SeoPackageSubscription::find($id);

        if (! $subscription) {
            return response()->json(['message' => 'Subscription not found.'], 404);
        }

        $payment_profile = null;

        if ($request->filled('payment_profile_id')) {
            $payment_profile = PaymentProfile::where('id', $request->input('payment_profile_id'))
                ->where('user_id', $subscription->user_id)
                ->first();

            if (! $payment_profile) {
                return response()->json(['message' => 'Saved card not found for this client.'], 404);
            }
        }

        $result = $this->billing_service->chargeCurrentPeriod(
            $subscription,
            SeoSubscriptionCharge::TRIGGER_MANUAL,
            Auth::user(),
            $payment_profile,
        );

        if (! $result['success']) {
            return response()->json([
                'message' => $result['message'],
                'charge'  => $result['charge'] ? new AdminSeoSubscriptionChargeResource($result['charge']->load('invoice')) : null,
            ], $result['status_code']);
        }

        return $this->detailResponse($subscription, $result['message']);
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

        $this->billing_service->cancel($subscription, $request->input('notes'));

        return response()->json(['data' => new AdminSeoPackageSubscriptionResource($subscription->load($this->eagerLoad))]);
    }

    private function detailResponse(SeoPackageSubscription $subscription, ?string $message = null): JsonResponse
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
            ],
            'message' => $message,
        ], fn ($value) => $value !== null));
    }
}
