<?php

namespace App\Http\Controllers\Admin\Transaction;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminTransactionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $per_page  = min((int) $request->input('per_page', 25), 100);
        $page      = max((int) $request->input('page', 1), 1);
        $search    = $request->input('search', '');
        $status    = $request->input('status', '');
        $type      = $request->input('type', '');
        $method    = $request->input('payment_method', '');
        $date_from = $request->input('date_from', '');
        $date_to   = $request->input('date_to', '');
        $sort_field     = $request->input('sort_field', 'created_at');
        $sort_direction = in_array($request->input('sort_direction'), ['asc', 'desc'])
            ? $request->input('sort_direction')
            : 'desc';

        $allowed_sort_fields = ['created_at', 'amount', 'status', 'type', 'payment_method'];
        if (! in_array($sort_field, $allowed_sort_fields)) {
            $sort_field = 'created_at';
        }

        $query = Transaction::with('user')
            ->orderBy($sort_field, $sort_direction);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('session_title', 'like', "%{$search}%")
                  ->orWhere('session_id', 'like', "%{$search}%")
                  ->orWhere('payment_intent_id', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhereHas('user', fn ($u) => $u->where('email', 'like', "%{$search}%")
                      ->orWhere('first_name', 'like', "%{$search}%")
                      ->orWhere('last_name', 'like', "%{$search}%"));
            });
        }

        if ($status) {
            $query->where('status', $status);
        }

        if ($type) {
            $query->where('type', $type);
        }

        if ($method) {
            $query->where('payment_method', $method);
        }

        if ($date_from) {
            $query->whereDate('created_at', '>=', $date_from);
        }

        if ($date_to) {
            $query->whereDate('created_at', '<=', $date_to);
        }

        $paginated = $query->paginate($per_page, ['*'], 'page', $page);

        $items = $paginated->getCollection()->map(fn ($tx) => $this->formatTransaction($tx));

        return response()->json([
            'data'         => $items,
            'current_page' => $paginated->currentPage(),
            'last_page'    => $paginated->lastPage(),
            'total'        => $paginated->total(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $transaction = Transaction::with('user')->find($id);

        if (! $transaction) {
            return response()->json(['message' => 'Transaction not found.'], 404);
        }

        return response()->json(['data' => $this->formatTransaction($transaction)]);
    }

    private function formatTransaction(Transaction $tx): array
    {
        return [
            'id'                => $tx->id,
            'type'              => $tx->type,
            'status'            => $tx->status,
            'amount'            => $tx->amount,
            'payment_method'    => $tx->payment_method,
            'payment_intent_id' => $tx->payment_intent_id,
            'session_id'        => $tx->session_id,
            'session_title'     => $tx->session_title,
            'order_id'          => $tx->order_id,
            'invoice_id'        => $tx->invoice_id,
            'description'       => $tx->description,
            'error_message'     => $tx->error_message,
            'metadata'          => $tx->metadata,
            'created_at'        => $tx->created_at?->toISOString(),
            'updated_at'        => $tx->updated_at?->toISOString(),
            'user'              => $tx->user ? [
                'id'         => $tx->user->id,
                'first_name' => $tx->user->first_name,
                'last_name'  => $tx->user->last_name,
                'email'      => $tx->user->email,
            ] : null,
        ];
    }
}
