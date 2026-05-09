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
            'search' => ['required', 'string'],
            'type'   => ['required', 'string', 'in:client'],
        ]);

        $search = $request->search;

        $users = User::whereHas('roles', fn ($q) => $q->where('name', 'client'))
            ->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            })
            ->select(['id', 'first_name', 'last_name', 'email', 'credit_balance'])
            ->limit(20)
            ->get();

        return response()->json([
            'data' => $users->map(fn (User $u) => [
                'id'             => $u->id,
                'first_name'     => $u->first_name,
                'last_name'      => $u->last_name,
                'email'          => $u->email,
                'credit_balance' => (float) $u->credit_balance,
            ]),
        ]);
    }
}
