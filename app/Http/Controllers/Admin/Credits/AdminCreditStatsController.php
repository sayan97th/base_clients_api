<?php

namespace App\Http\Controllers\Admin\Credits;

use App\Http\Controllers\Controller;
use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class AdminCreditStatsController extends Controller
{
    public function index(): JsonResponse
    {
        $total_credits_issued = CreditTransaction::where('type', 'credit')->sum('amount');

        $users_with_credits = User::where('credit_balance', '>', 0)->count();

        $credits_used_this_month = CreditTransaction::where('type', 'debit')
            ->whereBetween('created_at', [
                Carbon::now()->startOfMonth(),
                Carbon::now()->endOfMonth(),
            ])
            ->sum('amount');

        return response()->json([
            'total_credits_issued'    => (float) $total_credits_issued,
            'users_with_credits'      => $users_with_credits,
            'credits_used_this_month' => (float) $credits_used_this_month,
        ]);
    }
}
