<?php

namespace App\Http\Controllers\Admin\SeoPackages;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SeoPackages\StoreAdminSeoPackageRequest;
use App\Http\Requests\Admin\SeoPackages\UpdateAdminSeoPackageRequest;
use App\Models\SeoPackage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminSeoPackageController extends Controller
{
    public function index(): JsonResponse
    {
        $packages = SeoPackage::withCount('subscriptions as orders_count')
            ->withSum('subscriptions as revenue_total', 'total_amount')
            ->orderBy('sort_order')
            ->get();

        return response()->json($packages->map(fn (SeoPackage $package) => $this->formatPackage($package)));
    }

    public function show(string $id): JsonResponse
    {
        $package = SeoPackage::withCount('subscriptions as orders_count')
            ->withSum('subscriptions as revenue_total', 'total_amount')
            ->find($id);

        if (!$package) {
            return response()->json(['message' => 'SEO package not found.'], 404);
        }

        return response()->json($this->formatPackage($package));
    }

    public function store(StoreAdminSeoPackageRequest $request): JsonResponse
    {
        $data = $request->validated();

        $package = DB::transaction(function () use ($data): SeoPackage {
            if (!empty($data['is_most_popular'])) {
                SeoPackage::where('is_most_popular', true)->update(['is_most_popular' => false]);
            }

            $data['id'] = (string) Str::uuid();

            return SeoPackage::create($data);
        });

        $package->loadCount('subscriptions as orders_count')
            ->loadSum('subscriptions as revenue_total', 'total_amount');

        return response()->json($this->formatPackage($package), 201);
    }

    public function update(UpdateAdminSeoPackageRequest $request, string $id): JsonResponse
    {
        $package = SeoPackage::find($id);

        if (!$package) {
            return response()->json(['message' => 'SEO package not found.'], 404);
        }

        $data = $request->validated();

        DB::transaction(function () use ($package, $data): void {
            if (isset($data['is_most_popular']) && $data['is_most_popular']) {
                SeoPackage::where('is_most_popular', true)
                    ->where('id', '!=', $package->id)
                    ->update(['is_most_popular' => false]);
            }

            $package->update($data);
        });

        $package->refresh()
            ->loadCount('subscriptions as orders_count')
            ->loadSum('subscriptions as revenue_total', 'total_amount');

        return response()->json($this->formatPackage($package));
    }

    public function destroy(string $id): Response|JsonResponse
    {
        $package = SeoPackage::find($id);

        if (!$package) {
            return response()->json(['message' => 'SEO package not found.'], 404);
        }

        $activeSubscriptionsCount = $package->subscriptions()
            ->whereIn('status', ['pending', 'active', 'processing'])
            ->count();

        if ($activeSubscriptionsCount > 0) {
            return response()->json([
                'message' => 'Cannot delete a package with active subscriptions.',
            ], 409);
        }

        $package->delete();

        return response()->noContent();
    }

    private function formatPackage(SeoPackage $package): array
    {
        return [
            'id'              => $package->id,
            'name'            => $package->name,
            'slug'            => $package->slug,
            'price_per_month' => $package->price_per_month,
            'best_for'        => $package->best_for,
            'tagline'         => $package->tagline,
            'is_most_popular' => $package->is_most_popular,
            'is_active'       => $package->is_active,
            'sort_order'      => $package->sort_order,
            'features'        => $package->features ?? [],
            'orders_count'    => $package->orders_count ?? 0,
            'revenue_total'   => $package->revenue_total ?? 0,
            'created_at'      => $package->created_at?->toIso8601String(),
            'updated_at'      => $package->updated_at?->toIso8601String(),
        ];
    }
}
