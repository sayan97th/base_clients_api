<?php

namespace App\Http\Controllers\Admin\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\BacklinkOrder;
use App\Models\Invoice;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /** Distinct colors cycled per assignee so each person has a unique bar color. */
    private const TEAM_COLORS = [
        '#3B82F6', // blue
        '#10B981', // emerald
        '#F59E0B', // amber
        '#EF4444', // red
        '#8B5CF6', // violet
        '#06B6D4', // cyan
        '#84CC16', // lime
        '#F97316', // orange
        '#EC4899', // pink
        '#14B8A6', // teal
    ];

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
     * Returns capacity metrics per assigned admin user, computed from the
     * active (non-Cancelled, non-Live) link building order placements assigned
     * to each staff member via the "Assigned To" field.
     */
    public function teamCapacity(): JsonResponse
    {
        $team_colors  = self::TEAM_COLORS;
        $max_capacity = 50;

        $rows = DB::table('link_building_order_placements as p')
            ->join('users as u', 'p.assigned_admin_user_id', '=', 'u.id')
            ->whereNotNull('p.assigned_admin_user_id')
            ->whereNotIn('p.status', ['Cancelled', 'Live'])
            ->selectRaw(
                'p.assigned_admin_user_id as user_id,
                 TRIM(CONCAT(u.first_name, " ", u.last_name)) as name,
                 COUNT(p.id) as total_assigned'
            )
            ->groupBy('p.assigned_admin_user_id', 'u.first_name', 'u.last_name')
            ->orderByRaw('TRIM(CONCAT(u.first_name, " ", u.last_name))')
            ->get();

        $data = $rows->values()->map(function ($row, int $index) use ($team_colors, $max_capacity) {
            $total_assigned = (int) $row->total_assigned;
            $capacity_pct   = $max_capacity > 0
                ? min(100, (int) round(($total_assigned / $max_capacity) * 100))
                : 0;

            return [
                'team_id'        => $row->user_id,
                'name'           => $row->name,
                'color'          => $team_colors[$index % count($team_colors)],
                'capacity_pct'   => $capacity_pct,
                'total_assigned' => $total_assigned,
                'max_capacity'   => $max_capacity,
            ];
        });

        return response()->json(['data' => $data->values()]);
    }

    /**
     * GET /api/admin/dashboard/team-health
     *
     * Returns on-track vs delayed link building placement counts per assigned
     * admin user. A placement is delayed when estimated_delivery_date is past
     * today and its status is not Live or Cancelled.
     */
    public function teamHealth(): JsonResponse
    {
        $team_colors = self::TEAM_COLORS;
        $today       = Carbon::today();

        $placements = DB::table('link_building_order_placements as p')
            ->join('users as u', 'p.assigned_admin_user_id', '=', 'u.id')
            ->whereNotNull('p.assigned_admin_user_id')
            ->whereNotIn('p.status', ['Cancelled', 'Live'])
            ->selectRaw(
                'p.assigned_admin_user_id as user_id,
                 TRIM(CONCAT(u.first_name, " ", u.last_name)) as name,
                 p.estimated_delivery_date,
                 p.status'
            )
            ->orderByRaw('TRIM(CONCAT(u.first_name, " ", u.last_name))')
            ->get();

        $grouped = $placements->groupBy('user_id');

        $data  = [];
        $index = 0;

        foreach ($grouped as $user_id => $user_placements) {
            $name          = $user_placements->first()->name;
            $total_links   = $user_placements->count();
            $links_delayed = 0;

            foreach ($user_placements as $p) {
                if ($this->isDelayed($p->estimated_delivery_date, $p->status, $today)) {
                    $links_delayed++;
                }
            }

            $links_on_track = $total_links - $links_delayed;
            $health_pct     = $total_links > 0
                ? (int) round(($links_on_track / $total_links) * 100)
                : 100;

            $data[] = [
                'team_id'        => $user_id,
                'name'           => $name,
                'color'          => $team_colors[$index % count($team_colors)],
                'health_pct'     => $health_pct,
                'links_on_track' => $links_on_track,
                'total_links'    => $total_links,
                'links_delayed'  => $links_delayed,
            ];
            $index++;
        }

        return response()->json(['data' => $data]);
    }

    /**
     * Determines whether a link building placement is delayed.
     * A placement is delayed when estimated_delivery_date is in the past
     * and it is not yet Live or Cancelled.
     */
    private function isDelayed(?string $estimated_delivery_date, string $status, Carbon $today): bool
    {
        if (in_array($status, ['Live', 'Cancelled'], true)) {
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
