<?php

namespace App\Http\Controllers\Admin\PremiumMentions;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PremiumMentions\StorePremiumMentionsPlanRequest;
use App\Http\Requests\Admin\PremiumMentions\UpdatePremiumMentionsPlanRequest;
use App\Models\PremiumMentionsPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminPremiumMentionsPlanController extends Controller
{
    public function index(): JsonResponse
    {
        $plans = PremiumMentionsPlan::withCount('orders as orders_count')
            ->withSum('orders as revenue_total', 'total_amount')
            ->orderBy('sort_order')
            ->get();

        return response()->json($plans->map(fn (PremiumMentionsPlan $plan) => $this->formatPlan($plan)));
    }

    public function show(string $id): JsonResponse
    {
        $plan = PremiumMentionsPlan::withCount('orders as orders_count')
            ->withSum('orders as revenue_total', 'total_amount')
            ->find($id);

        if (!$plan) {
            return response()->json(['message' => 'Plan not found.'], 404);
        }

        return response()->json($this->formatPlan($plan));
    }

    public function store(StorePremiumMentionsPlanRequest $request): JsonResponse
    {
        $data = $request->validated();

        $plan = DB::transaction(function () use ($data): PremiumMentionsPlan {
            if (!empty($data['is_most_popular'])) {
                PremiumMentionsPlan::where('is_most_popular', true)->update(['is_most_popular' => false]);
            }

            $data['id'] = (string) Str::uuid();

            return PremiumMentionsPlan::create($data);
        });

        $plan->loadCount('orders as orders_count')
            ->loadSum('orders as revenue_total', 'total_amount');

        return response()->json($this->formatPlan($plan), 201);
    }

    public function update(UpdatePremiumMentionsPlanRequest $request, string $id): JsonResponse
    {
        $plan = PremiumMentionsPlan::find($id);

        if (!$plan) {
            return response()->json(['message' => 'Plan not found.'], 404);
        }

        $data = $request->validated();

        DB::transaction(function () use ($plan, $data): void {
            if (isset($data['is_most_popular']) && $data['is_most_popular']) {
                PremiumMentionsPlan::where('is_most_popular', true)
                    ->where('id', '!=', $plan->id)
                    ->update(['is_most_popular' => false]);
            }

            $plan->update($data);
        });

        $plan->refresh()
            ->loadCount('orders as orders_count')
            ->loadSum('orders as revenue_total', 'total_amount');

        return response()->json($this->formatPlan($plan));
    }

    public function destroy(string $id): JsonResponse
    {
        $plan = PremiumMentionsPlan::find($id);

        if (!$plan) {
            return response()->json(['message' => 'Plan not found.'], 404);
        }

        $activeOrdersCount = $plan->orders()
            ->whereIn('status', ['pending', 'processing'])
            ->count();

        if ($activeOrdersCount > 0) {
            return response()->json([
                'message' => 'Cannot delete a plan with active orders. Disable it instead.',
            ], 409);
        }

        $plan->delete();

        return response()->json(null, 204);
    }

    private function formatPlan(PremiumMentionsPlan $plan): array
    {
        return [
            'id'                   => $plan->id,
            'name'                 => $plan->name,
            'price_per_month'      => $plan->price_per_month,
            'total_placements'     => $plan->total_placements,
            'exclusive_placements' => $plan->exclusive_placements,
            'core_placements'      => $plan->core_placements,
            'support_placements'   => $plan->support_placements,
            'best_for'             => $plan->best_for,
            'tagline'              => $plan->tagline,
            'is_most_popular'      => $plan->is_most_popular,
            'is_active'            => $plan->is_active,
            'sort_order'           => $plan->sort_order,
            'orders_count'         => $plan->orders_count ?? 0,
            'revenue_total'        => $plan->revenue_total ?? 0,
            'created_at'           => $plan->created_at?->toIso8601String(),
            'updated_at'           => $plan->updated_at?->toIso8601String(),
        ];
    }
}
