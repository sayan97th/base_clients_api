<?php

namespace App\Http\Controllers\Client\Credits;

use App\Http\Controllers\Controller;
use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CreditApplyDiscountController extends Controller
{
    public function apply(Request $request): JsonResponse
    {
        $request->validate([
            'amount'            => ['required', 'integer', 'min:1'],
            'payment_intent_id' => ['nullable', 'string', 'max:255'],
            'description'       => ['nullable', 'string', 'max:255'],
        ]);

        $amount            = (int) $request->input('amount');
        $payment_intent_id = $request->input('payment_intent_id');
        $description       = $request->input('description');

        /** @var User $user */
        $user = $request->user();

        if ((int) $user->credit_balance < $amount) {
            return response()->json([
                'message' => 'Insufficient credit balance.',
                'errors'  => [
                    'amount' => ['Your credit balance is insufficient for this transaction.'],
                ],
            ], 422);
        }

        if ($payment_intent_id) {
            $already_used = CreditTransaction::where('payment_intent_id', $payment_intent_id)->exists();

            if ($already_used) {
                return response()->json([
                    'message' => 'Credits for this payment have already been applied.',
                    'errors'  => [
                        'payment_intent_id' => ['A credit deduction for this payment intent already exists.'],
                    ],
                ], 409);
            }
        }

        $transaction = DB::transaction(function () use ($user, $amount, $payment_intent_id, $description) {
            User::where('id', $user->id)->lockForUpdate()->first();
            $user->decrement('credit_balance', $amount);

            return CreditTransaction::create([
                'user_id'           => $user->id,
                'amount'            => $amount,
                'type'              => 'debit',
                'description'       => $description,
                'payment_intent_id' => $payment_intent_id,
                'created_by'        => null,
            ]);
        });

        $user->refresh();

        return response()->json([
            'success'           => true,
            'credits_applied'   => $amount,
            'remaining_balance' => (int) $user->credit_balance,
            'transaction_id'    => $transaction->id,
        ]);
    }
}
