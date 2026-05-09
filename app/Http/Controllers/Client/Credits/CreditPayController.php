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
            'amount'      => ['required', 'integer', 'min:1'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $amount = (int) $request->amount;

        /** @var User $user */
        $user = auth()->user();

        if ((int) $user->credit_balance < $amount) {
            return response()->json([
                'message' => 'Insufficient credit balance.',
                'errors'  => [
                    'amount' => ['The requested amount exceeds your available credit balance.'],
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
            'remaining_balance' => (int) $user->credit_balance,
            'transaction_id'    => $transaction->id,
        ]);
    }
}
