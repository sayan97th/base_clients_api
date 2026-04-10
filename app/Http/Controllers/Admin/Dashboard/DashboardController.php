<?php

namespace App\Http\Controllers\Admin\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\BacklinkOrder;
use App\Models\Invoice;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    /**
     * GET /api/admin/dashboard/summary
     *
     * Returns all KPI counts needed for the stat cards in a single request.
     */
    public function summary(): JsonResponse
    {
        $pending_statuses = ['New Request', 'Reviewing', 'Ordered', 'Pending'];

        return response()->json([
            'total_orders'        => BacklinkOrder::count(),
            'pending_orders'      => BacklinkOrder::whereIn('status', $pending_statuses)->count(),
            'total_clients'       => BacklinkOrder::whereNotNull('client')
                                        ->where('client', '!=', '')
                                        ->distinct()
                                        ->count('client'),
            'total_paid_invoices' => Invoice::where('status', 'paid')->count(),
        ]);
    }

    /**
     * GET /api/admin/dashboard/team-capacity
     *
     * Returns capacity metrics per staff team member, computed from assigned backlink orders.
     */
    public function teamCapacity(): JsonResponse
    {
        $staff_users = User::whereHas('roles', fn ($q) => $q->where('name', 'staff'))
            ->get(['id', 'first_name', 'last_name', 'staff_capacity']);

        $data = $staff_users->map(function (User $user) {
            $total_assigned = BacklinkOrder::where('link_builder_user_id', $user->id)
                ->whereNotIn('status', ['Cancelled', 'Live'])
                ->count();

            $max_capacity = $user->staff_capacity ?: 25;
            $capacity_pct = $max_capacity > 0
                ? min(100, (int) round(($total_assigned / $max_capacity) * 100))
                : 0;

            return [
                'user_id'        => $user->id,
                'name'           => $user->first_name . ' ' . $user->last_name,
                'capacity_pct'   => $capacity_pct,
                'total_assigned' => $total_assigned,
                'max_capacity'   => $max_capacity,
            ];
        })->values();

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/admin/dashboard/team-health
     *
     * Returns link health statistics per staff team member.
     */
    public function teamHealth(): JsonResponse
    {
        $today = Carbon::today();

        $staff_users = User::whereHas('roles', fn ($q) => $q->where('name', 'staff'))
            ->get(['id', 'first_name', 'last_name']);

        $data = $staff_users->map(function (User $user) use ($today) {
            $assigned_orders = BacklinkOrder::where('link_builder_user_id', $user->id)
                ->where('status', '!=', 'Cancelled')
                ->get(['estimated_delivery_date', 'status']);

            $total_links   = $assigned_orders->count();
            $links_delayed = 0;

            foreach ($assigned_orders as $order) {
                if ($this->isDelayed($order->estimated_delivery_date, $order->status, $today)) {
                    $links_delayed++;
                }
            }

            $links_on_track = $total_links - $links_delayed;
            $health_pct     = $total_links > 0
                ? (int) round(($links_on_track / $total_links) * 100)
                : 0;

            return [
                'user_id'        => $user->id,
                'name'           => $user->first_name . ' ' . $user->last_name,
                'health_pct'     => $health_pct,
                'links_on_track' => $links_on_track,
                'total_links'    => $total_links,
                'links_delayed'  => $links_delayed,
            ];
        })->values();

        return response()->json(['data' => $data]);
    }

    /**
     * Determines whether a backlink order is delayed.
     * A link is delayed when: estimated_delivery_date < today AND status != 'Live'.
     */
    private function isDelayed(?string $estimated_delivery_date, string $status, Carbon $today): bool
    {
        if ($status === 'Live') {
            return false;
        }

        if (empty($estimated_delivery_date)) {
            return false;
        }

        try {
            $delivery_date = Carbon::createFromFormat('m/d/Y', $estimated_delivery_date)->startOfDay();
            return $delivery_date->lt($today);
        } catch (\Exception) {
            return false;
        }
    }
}
