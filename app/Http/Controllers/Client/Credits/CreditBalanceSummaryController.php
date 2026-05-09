<?php

namespace App\Http\Controllers\Client\Credits;

use App\Http\Controllers\Controller;
use App\Models\CreditTransaction;
use Illuminate\Http\JsonResponse;

class CreditBalanceSummaryController extends Controller
{
    public function show(): JsonResponse
    {
        $user    = auth()->user();
        $balance = (int) $user->credit_balance;

        $recent_transactions = CreditTransaction::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(fn (CreditTransaction $t) => [
                'id'          => $t->id,
                'amount'      => (int) $t->amount,
                'type'        => $t->type,
                'description' => $t->description,
                'created_at'  => $t->created_at,
            ]);

        return response()->json([
            'balance'             => $balance,
            'dollar_value'        => (float) $balance,
            'recent_transactions' => $recent_transactions,
        ]);
    }
}
