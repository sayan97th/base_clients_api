<?php

namespace App\Http\Controllers\Admin\Credits;

use App\Http\Controllers\Controller;
use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminCreditAssignController extends Controller
{
    public function assign(Request $request): JsonResponse
    {
        $request->validate([
            'user_id'     => ['required', 'integer', 'exists:users,id'],
            'amount'      => ['required', 'integer', 'min:1'],
            'type'        => ['required', 'string', 'in:credit,debit'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $amount = (int) $request->amount;

        try {
            $result = DB::transaction(function () use ($request, $amount) {
                $user = User::whereHas('roles', fn ($q) => $q->where('name', 'client'))
                    ->lockForUpdate()
                    ->find($request->user_id);

                if (!$user) {
                    throw new ModelNotFoundException();
                }

                if ($request->type === 'debit' && (int) $user->credit_balance < $amount) {
                    throw new \DomainException('insufficient_balance');
                }

                if ($request->type === 'credit') {
                    $user->increment('credit_balance', $amount);
                } else {
                    $user->decrement('credit_balance', $amount);
                }

                $transaction = CreditTransaction::create([
                    'user_id'     => $user->id,
                    'amount'      => $amount,
                    'type'        => $request->type,
                    'description' => $request->description,
                    'created_by'  => auth()->id(),
                ]);

                return ['user' => $user->fresh(), 'transaction' => $transaction];
            });
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'User not found.'], 404);
        } catch (\DomainException) {
            return response()->json([
                'message' => 'Insufficient credit balance.',
                'errors'  => [
                    'amount' => ['Cannot deduct more credits than the user currently holds.'],
                ],
            ], 422);
        }

        $transaction = $result['transaction']->load('user:id,first_name,last_name,email');
        $user        = $result['user'];

        return response()->json([
            'success'     => true,
            'new_balance' => (int) $user->credit_balance,
            'transaction' => [
                'id'          => $transaction->id,
                'user_id'     => $transaction->user_id,
                'user'        => [
                    'id'         => $transaction->user->id,
                    'first_name' => $transaction->user->first_name,
                    'last_name'  => $transaction->user->last_name,
                    'email'      => $transaction->user->email,
                ],
                'amount'      => (int) $transaction->amount,
                'type'        => $transaction->type,
                'description' => $transaction->description,
                'created_by'  => $transaction->created_by,
                'created_at'  => $transaction->created_at,
            ],
        ]);
    }
}
