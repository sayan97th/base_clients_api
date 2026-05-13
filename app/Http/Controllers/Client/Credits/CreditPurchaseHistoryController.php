<?php

namespace App\Http\Controllers\Client\Credits;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CreditPurchaseHistoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $paginator = $user->creditPurchases()
            ->orderByDesc('created_at')
            ->paginate(10);

        $data = $paginator->getCollection()->map(fn ($purchase) => [
            'id'                => $purchase->id,
            'package_id'        => $purchase->package_id,
            'package_name'      => $purchase->package_name,
            'credits_amount'    => $purchase->credits_amount,
            'amount_paid'       => (float) $purchase->amount_paid,
            'payment_intent_id' => $purchase->payment_intent_id,
            'status'            => $purchase->status,
            'created_at'        => $purchase->created_at->toISOString(),
        ]);

        return response()->json([
            'data'         => $data,
            'current_page' => $paginator->currentPage(),
            'last_page'    => $paginator->lastPage(),
            'total'        => $paginator->total(),
        ]);
    }
}
