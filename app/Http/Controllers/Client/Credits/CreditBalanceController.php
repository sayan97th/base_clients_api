<?php

namespace App\Http\Controllers\Client\Credits;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class CreditBalanceController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'balance' => (int) auth()->user()->credit_balance,
        ]);
    }
}
