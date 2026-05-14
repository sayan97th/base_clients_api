<?php

namespace App\Http\Controllers\Admin\Credits;

use App\Http\Controllers\Controller;
use App\Models\CreditPurchase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminCreditPurchaseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'page'      => ['nullable', 'integer', 'min:1'],
            'search'    => ['nullable', 'string', 'max:255'],
            'status'    => ['nullable', 'string', Rule::in(['completed', 'pending', 'failed', 'refunded'])],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to'   => ['nullable', 'date_format:Y-m-d'],
        ]);

        $query = CreditPurchase::with(['user:id,first_name,last_name,email'])
            ->orderBy('created_at', 'desc');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name',  'like', "%{$search}%")
                  ->orWhere('email',      'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $paginator = $query->paginate(15);

        return response()->json([
            'data'         => $paginator->map(fn (CreditPurchase $p) => [
                'id'                => $p->id,
                'package_id'        => $p->package_id,
                'package_name'      => $p->package_name,
                'credits_amount'    => $p->credits_amount,
                'amount_paid'       => (float) $p->amount_paid,
                'payment_intent_id' => $p->payment_intent_id,
                'status'            => $p->status,
                'created_at'        => $p->created_at,
                'user'              => $p->user ? [
                    'id'         => $p->user->id,
                    'first_name' => $p->user->first_name,
                    'last_name'  => $p->user->last_name,
                    'email'      => $p->user->email,
                ] : null,
            ]),
            'current_page' => $paginator->currentPage(),
            'last_page'    => $paginator->lastPage(),
            'total'        => $paginator->total(),
        ]);
    }
}
