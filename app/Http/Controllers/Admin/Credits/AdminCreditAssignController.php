<?php

namespace App\Http\Controllers\Admin\Credits;

use App\Http\Controllers\Controller;
use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminCreditAssignController extends Controller
{
    public function assign(Request $request): JsonResponse
    {
        $request->validate([
            'user_id'     => ['required', 'integer', 'exists:users,id'],
            'amount'      => ['required', 'numeric', 'gt:0'],
            'type'        => ['required', 'string', 'in:credit,debit'],
            'description' => ['nullable', 'string'],
        ]);

        $user = User::whereHas('roles', fn ($q) => $q->where('name', 'client'))
            ->find($request->user_id);

        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $amount = (float) $request->amount;

        if ($request->type === 'debit' && $user->credit_balance < $amount) {
            return response()->json([
                'message' => 'Cannot deduct more credits than the user currently has.',
                'errors'  => [
                    'amount' => ["The user only has {$user->credit_balance} credits. Cannot deduct {$amount}."],
                ],
            ], 422);
        }

        $transaction = DB::transaction(function () use ($user, $amount, $request) {
            if ($request->type === 'credit') {
                $user->increment('credit_balance', $amount);
            } else {
                $user->decrement('credit_balance', $amount);
            }

            return CreditTransaction::create([
                'user_id'     => $user->id,
                'amount'      => $amount,
                'type'        => $request->type,
                'description' => $request->description,
                'created_by'  => auth()->id(),
            ]);
        });

        $user->refresh();
        $transaction->load('user:id,first_name,last_name,email');

        return response()->json([
            'success'     => true,
            'new_balance' => (float) $user->credit_balance,
            'transaction' => [
                'id'          => $transaction->id,
                'user_id'     => $transaction->user_id,
                'user'        => [
                    'id'         => $transaction->user->id,
                    'first_name' => $transaction->user->first_name,
                    'last_name'  => $transaction->user->last_name,
                    'email'      => $transaction->user->email,
                ],
                'amount'      => (float) $transaction->amount,
                'type'        => $transaction->type,
                'description' => $transaction->description,
                'created_by'  => $transaction->created_by,
                'created_at'  => $transaction->created_at,
            ],
        ]);
    }
}
