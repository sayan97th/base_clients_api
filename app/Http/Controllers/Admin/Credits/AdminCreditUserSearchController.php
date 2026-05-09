<?php

namespace App\Http\Controllers\Admin\Credits;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminCreditUserSearchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'type'     => ['nullable', 'string', 'in:client'],
            'search'   => ['nullable', 'string'],
            'page'     => ['nullable', 'integer', 'min:1'],
            'sort_by'  => ['nullable', 'string'],
            'sort_dir' => ['nullable', 'string'],
        ]);

        $allowed_sort = ['first_name', 'credit_balance'];
        $sort_by      = in_array($request->sort_by, $allowed_sort, true) ? $request->sort_by : 'first_name';
        $sort_dir     = $request->sort_dir === 'desc' ? 'desc' : 'asc';

        $users = User::whereHas('roles', fn ($q) => $q->where('name', 'client'))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%' . $request->search . '%';
                $q->where(function ($q) use ($term) {
                    $q->where('first_name', 'like', $term)
                      ->orWhere('last_name', 'like', $term)
                      ->orWhere('email', 'like', $term);
                });
            })
            ->select(['id', 'first_name', 'last_name', 'email', 'credit_balance'])
            ->orderBy($sort_by, $sort_dir)
            ->paginate(20);

        return response()->json([
            'data'         => $users->map(fn (User $u) => [
                'id'             => $u->id,
                'first_name'     => $u->first_name,
                'last_name'      => $u->last_name,
                'email'          => $u->email,
                'credit_balance' => (int) $u->credit_balance,
            ]),
            'current_page' => $users->currentPage(),
            'last_page'    => $users->lastPage(),
            'total'        => $users->total(),
        ]);
    }
}
