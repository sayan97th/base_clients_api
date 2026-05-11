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
            'description'       => ['nullable', 'string', 'max:500'],
        ]);

        $amount            = (int) $request->input('amount');
        $payment_intent_id = $request->input('payment_intent_id');
        $description       = $request->input('description');

        /** @var User $user */
        $user = $request->user();

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

        try {
            $transaction = DB::transaction(function () use ($user, $amount, $payment_intent_id, $description) {
                User::where('id', $user->id)->lockForUpdate()->first();
                $user->refresh();

                if ((int) $user->credit_balance < $amount) {
                    throw new \DomainException('insufficient_balance');
                }

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
        } catch (\DomainException) {
            return response()->json([
                'message' => 'Insufficient credits.',
                'errors'  => [
                    'amount' => ['You do not have enough credits to complete this payment.'],
                ],
            ], 422);
        }

        $user->refresh();

        return response()->json([
            'success'           => true,
            'credits_applied'   => $amount,
            'remaining_balance' => (int) $user->credit_balance,
            'transaction_id'    => $transaction->id,
        ]);
    }
}
