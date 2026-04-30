<?php

namespace App\Http\Controllers\Admin\SeoPackages;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SeoPackages\CancelAdminSeoPackageSubscriptionRequest;
use App\Http\Requests\Admin\SeoPackages\StoreAdminSeoPackageSubscriptionRequest;
use App\Http\Resources\AdminSeoPackageSubscriptionResource;
use App\Models\SeoPackageSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminSeoPackageSubscriptionController extends Controller
{
    private array $eagerLoad = [
        'user:id,first_name,last_name,email,organization_id',
        'user.organization:id,name',
        'package:id,name,slug,price_per_month',
    ];

    public function index(Request $request): JsonResponse
    {
        $query = SeoPackageSubscription::with($this->eagerLoad);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('user', function ($user_q) use ($search) {
                    $user_q->where('first_name', 'like', "%{$search}%")
                           ->orWhere('last_name', 'like', "%{$search}%")
                           ->orWhere('email', 'like', "%{$search}%");
                })->orWhereHas('package', function ($pkg_q) use ($search) {
                    $pkg_q->where('name', 'like', "%{$search}%");
                });
            });
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($package_id = $request->query('package_id')) {
            $query->where('seo_package_id', $package_id);
        }

        $allowed_sort_fields = ['starts_at', 'ends_at', 'created_at', 'status'];
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

        return response()->json([
            'data' => [
                'total'     => $total,
                'active'    => (int) ($by_status['active']    ?? 0),
                'cancelled' => (int) ($by_status['cancelled'] ?? 0),
                'expired'   => (int) ($by_status['expired']   ?? 0),
            ],
        ]);
    }

    public function store(StoreAdminSeoPackageSubscriptionRequest $request): JsonResponse
    {
        $data = $request->validated();

        $subscription = DB::transaction(function () use ($data) {
            return SeoPackageSubscription::create([
                'user_id'        => $data['user_id'],
                'seo_package_id' => $data['package_id'],
                'status'         => 'active',
                'starts_at'      => $data['starts_at'],
                'ends_at'        => $data['ends_at'] ?? null,
                'notes'          => $data['notes'] ?? null,
            ]);
        });

        $subscription->load($this->eagerLoad);

        return response()->json(['data' => new AdminSeoPackageSubscriptionResource($subscription)], 201);
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

        $subscription->status       = 'cancelled';
        $subscription->cancelled_at = now();

        if ($request->filled('notes')) {
            $subscription->notes = $request->input('notes');
        }

        $subscription->save();

        return response()->json(['data' => new AdminSeoPackageSubscriptionResource($subscription)]);
    }
}
