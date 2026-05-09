<?php

namespace App\Http\Controllers\Client\Credits;

use App\Http\Controllers\Controller;
use App\Models\CreditTransaction;
use Illuminate\Http\JsonResponse;

class CreditTransactionController extends Controller
{
    public function index(): JsonResponse
    {
        $paginator = CreditTransaction::where('user_id', auth()->id())
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return response()->json([
            'data'         => $paginator->map(fn (CreditTransaction $t) => [
                'id'          => $t->id,
                'amount'      => (int) $t->amount,
                'type'        => $t->type,
                'description' => $t->description,
                'created_at'  => $t->created_at,
            ]),
            'current_page' => $paginator->currentPage(),
            'last_page'    => $paginator->lastPage(),
            'total'        => $paginator->total(),
        ]);
    }
}
