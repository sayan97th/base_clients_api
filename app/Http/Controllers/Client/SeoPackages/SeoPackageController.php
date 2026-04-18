<?php

namespace App\Http\Controllers\Client\SeoPackages;

use App\Http\Controllers\Controller;
use App\Models\SeoPackage;
use Illuminate\Http\JsonResponse;

class SeoPackageController extends Controller
{
    public function index(): JsonResponse
    {
        $packages = SeoPackage::where('is_active', true)
            ->orderBy('sort_order', 'asc')
            ->orderBy('price_per_month', 'asc')
            ->get();

        $data = $packages->map(fn ($package) => [
            'id'              => $package->id,
            'name'            => $package->name,
            'slug'            => $package->slug,
            'price_per_month' => $package->price_per_month,
            'best_for'        => $package->best_for,
            'is_most_popular' => $package->is_most_popular,
            'is_active'       => $package->is_active,
            'features'        => $package->features,
        ]);

        return response()->json(['data' => $data]);
    }
}
