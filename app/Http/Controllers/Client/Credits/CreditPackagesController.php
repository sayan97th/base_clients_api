<?php

namespace App\Http\Controllers\Client\Credits;

use App\Http\Controllers\Controller;
use App\Models\CreditPackage;
use Illuminate\Http\JsonResponse;

class CreditPackagesController extends Controller
{
    public function index(): JsonResponse
    {
        $packages = CreditPackage::where('is_active', true)
            ->orderBy('credits')
            ->get()
            ->map(fn (CreditPackage $package) => [
                'id'             => $package->id,
                'name'           => $package->name,
                'credits'        => $package->credits,
                'price'          => (float) $package->price,
                'original_price' => (float) $package->original_price,
                'discount_pct'   => $package->discount_pct,
                'description'    => $package->description,
                'is_popular'     => $package->is_popular,
            ]);

        return response()->json($packages);
    }
}
