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

        try {
            $transaction = DB::transaction(function () use ($user, $amount, $request) {
                User::where('id', $user->id)->lockForUpdate()->first();
                $user->refresh();

                if ((int) $user->credit_balance < $amount) {
                    throw new \DomainException('insufficient_balance');
                }

                $user->decrement('credit_balance', $amount);

                return CreditTransaction::create([
                    'user_id'     => $user->id,
                    'amount'      => $amount,
                    'type'        => 'debit',
                    'description' => $request->input('description'),
                    'created_by'  => null,
                ]);
            });
        } catch (\DomainException) {
            return response()->json([
                'message' => 'Insufficient credit balance.',
                'errors'  => [
                    'amount' => ['The requested amount exceeds your available balance.'],
                ],
            ], 422);
        }

        $user->refresh();

        return response()->json([
            'success'           => true,
            'remaining_balance' => (int) $user->credit_balance,
            'transaction_id'    => $transaction->id,
        ]);
    }
}
