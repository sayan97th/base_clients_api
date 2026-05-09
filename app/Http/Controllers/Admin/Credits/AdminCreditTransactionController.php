<?php

namespace App\Http\Controllers\Admin\Credits;

use App\Http\Controllers\Controller;
use App\Models\CreditTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminCreditTransactionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = CreditTransaction::with(['user:id,first_name,last_name,email'])
            ->orderBy('created_at', 'desc');

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('type') && in_array($request->type, ['credit', 'debit'], true)) {
            $query->where('type', $request->type);
        }

        $paginator = $query->paginate(15);

        return response()->json([
            'data'         => $paginator->map(fn (CreditTransaction $t) => [
                'id'          => $t->id,
                'user_id'     => $t->user_id,
                'user'        => $t->user ? [
                    'id'         => $t->user->id,
                    'first_name' => $t->user->first_name,
                    'last_name'  => $t->user->last_name,
                    'email'      => $t->user->email,
                ] : null,
                'amount'      => (float) $t->amount,
                'type'        => $t->type,
                'description' => $t->description,
                'created_by'  => $t->created_by,
                'created_at'  => $t->created_at,
            ]),
            'current_page' => $paginator->currentPage(),
            'last_page'    => $paginator->lastPage(),
            'total'        => $paginator->total(),
        ]);
    }
}
