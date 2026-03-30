<?php

namespace App\Http\Controllers\Client\LinkBuilding;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OrderPlacementsController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1'],
            'page'     => ['nullable', 'integer', 'min:1'],
            'search'   => ['nullable', 'string', 'max:255'],
            'status'   => ['nullable', 'string', Rule::in(['pending', 'processing', 'completed', 'cancelled'])],
        ]);

        /** @var User $user */
        $user     = auth()->user();
        $per_page = min((int) $request->get('per_page', 10), 100);
        $search   = $request->get('search');
        $status   = $request->get('status');

        $query = DB::table('link_building_order_placements as p')
            ->join('link_building_order_items as i', 'i.id', '=', 'p.order_item_id')
            ->join('link_building_orders as o', 'o.id', '=', 'i.order_id')
            ->join('dr_tiers as dt', 'dt.id', '=', 'i.dr_tier_id')
            ->where('o.user_id', $user->id)
            ->where('o.is_hidden', false)
            ->select([
                'o.id as order_id',
                'o.created_at as start_date',
                'dt.dr_label as dr_type',
                'p.keyword',
                'p.landing_page',
                'o.status',
                'p.live_link',
                'p.completed_date',
                'p.dr',
            ])
            ->orderBy('o.created_at', 'desc');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('o.id', 'like', "%{$search}%")
                  ->orWhere('p.keyword', 'like', "%{$search}%")
                  ->orWhere('p.landing_page', 'like', "%{$search}%")
                  ->orWhere('o.status', 'like', "%{$search}%");
            });
        }

        if ($status) {
            $query->where('o.status', $status);
        }

        $paginator = $query->paginate($per_page);

        return response()->json([
            'data'         => $paginator->items(),
            'current_page' => $paginator->currentPage(),
            'last_page'    => $paginator->lastPage(),
            'per_page'     => $paginator->perPage(),
            'total'        => $paginator->total(),
        ]);
    }
}
