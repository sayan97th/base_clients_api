<?php

namespace App\Http\Controllers\Admin\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AdminTeam;
use App\Models\BacklinkOrder;
use App\Models\Invoice;
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
     * Returns capacity metrics per admin team, computed from assigned
     * link building order placements vs the team's max_capacity setting.
     */
    public function teamCapacity(): JsonResponse
    {
        $teams = AdminTeam::where('is_active', true)
            ->withCount([
                'placements as total_assigned' => fn ($q) => $q->whereNotIn('status', ['Cancelled', 'Live']),
            ])
            ->orderBy('name')
            ->get();

        $data = $teams->map(function (AdminTeam $team) {
            $max_capacity   = $team->max_capacity ?: 50;
            $total_assigned = (int) $team->total_assigned;
            $capacity_pct   = $max_capacity > 0
                ? min(100, (int) round(($total_assigned / $max_capacity) * 100))
                : 0;

            return [
                'team_id'        => $team->id,
                'name'           => $team->name,
                'color'          => $team->color,
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
     * Returns on-track vs delayed link building placement counts per admin team.
     */
    public function teamHealth(): JsonResponse
    {
        $today = Carbon::today();

        $teams = AdminTeam::where('is_active', true)
            ->with(['placements' => function ($q) {
                $q->whereNotIn('status', ['Cancelled', 'Live'])
                  ->select(['id', 'admin_team_id', 'estimated_delivery_date', 'status']);
            }])
            ->orderBy('name')
            ->get();

        $data = $teams->map(function (AdminTeam $team) use ($today) {
            $placements    = $team->placements;
            $total_links   = $placements->count();
            $links_delayed = 0;

            foreach ($placements as $placement) {
                if ($this->isDelayed($placement->estimated_delivery_date, $placement->status, $today)) {
                    $links_delayed++;
                }
            }

            $links_on_track = $total_links - $links_delayed;
            $health_pct     = $total_links > 0
                ? (int) round(($links_on_track / $total_links) * 100)
                : 100;

            return [
                'team_id'        => $team->id,
                'name'           => $team->name,
                'color'          => $team->color,
                'health_pct'     => $health_pct,
                'links_on_track' => $links_on_track,
                'total_links'    => $total_links,
                'links_delayed'  => $links_delayed,
            ];
        })->values();

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
