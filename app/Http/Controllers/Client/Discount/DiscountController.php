<?php

namespace App\Http\Controllers\Client\Discount;

use App\Http\Controllers\Controller;
use App\Models\Discount;
use Illuminate\Http\JsonResponse;

class DiscountController extends Controller
{
    public function active(): JsonResponse
    {
        $discounts = Discount::where('is_active', true)->get();

        return response()->json(['data' => $discounts]);
    }
}
