<?php

namespace App\Http\Controllers\Client\Credits;

use App\Http\Controllers\Controller;
use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CreditPayController extends Controller
{
    public function pay(Request $request): JsonResponse
    {
        $request->validate([
            'amount'      => ['required', 'numeric', 'gt:0'],
            'description' => ['nullable', 'string'],
        ]);

        $amount = (float) $request->amount;

        /** @var User $user */
        $user = auth()->user();

        if ($user->credit_balance < $amount) {
            return response()->json([
                'message' => 'Insufficient credit balance.',
                'errors'  => [
                    'amount' => ['Your credit balance is insufficient for this transaction.'],
                ],
            ], 422);
        }

        $transaction = DB::transaction(function () use ($user, $amount, $request) {
            $user->decrement('credit_balance', $amount);

            return CreditTransaction::create([
                'user_id'     => $user->id,
                'amount'      => $amount,
                'type'        => 'debit',
                'description' => $request->description,
                'created_by'  => null,
            ]);
        });

        $user->refresh();

        return response()->json([
            'success'           => true,
            'remaining_balance' => (float) $user->credit_balance,
            'transaction_id'    => $transaction->id,
        ]);
    }
}
