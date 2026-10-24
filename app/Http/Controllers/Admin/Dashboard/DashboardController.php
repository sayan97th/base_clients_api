<?php

namespace App\Http\Controllers\Admin\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\BacklinkOrder;
use App\Models\Invoice;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DashboardController extends Controller
{
    /** Distinct colors cycled per team member so each person has a unique bar color. */
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
     * Roles held by the link building team. Accounts that only hold other admin
     * side roles (super_admin, owner) are developer or QA accounts, so their
     * "Assigned To" value is ignored by the team widgets.
     */
    private const TEAM_MEMBER_ROLES = ['admin', 'staff'];

    /** Placement statuses that no longer count as open work. */
    private const CLOSED_STATUSES = ['Cancelled', 'Live'];

    /** Open placements a single team member can handle at once. */
    private const MAX_CAPACITY = 50;

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
     * Returns capacity metrics per link building team member, computed from the
     * open (non-Cancelled, non-Live) placements credited to each member.
     */
    public function teamCapacity(): JsonResponse
    {
        $data = $this->groupOpenPlacementsByTeamMember()->map(function (array $member, int $index) {
            $total_assigned = $member['placements']->count();
            $capacity_pct   = min(100, (int) round(($total_assigned / self::MAX_CAPACITY) * 100));

            return [
                'team_id'        => $member['team_id'],
                'name'           => $member['name'],
                'color'          => self::TEAM_COLORS[$index % count(self::TEAM_COLORS)],
                'capacity_pct'   => $capacity_pct,
                'total_assigned' => $total_assigned,
                'max_capacity'   => self::MAX_CAPACITY,
            ];
        });

        return response()->json(['data' => $data->values()]);
    }

    /**
     * GET /api/admin/dashboard/team-health
     *
     * Returns on-track vs delayed placement counts per link building team member.
     * A placement is delayed when estimated_delivery_date is past today and its
     * status is not Live or Cancelled.
     */
    public function teamHealth(): JsonResponse
    {
        $today = Carbon::today();

        $data = $this->groupOpenPlacementsByTeamMember()->map(function (array $member, int $index) use ($today) {
            $total_links   = $member['placements']->count();
            $links_delayed = $member['placements']
                ->filter(fn ($p) => $this->isDelayed($p->estimated_delivery_date, $p->status, $today))
                ->count();

            $links_on_track = $total_links - $links_delayed;
            $health_pct     = $total_links > 0
                ? (int) round(($links_on_track / $total_links) * 100)
                : 100;

            return [
                'team_id'        => $member['team_id'],
                'name'           => $member['name'],
                'color'          => self::TEAM_COLORS[$index % count(self::TEAM_COLORS)],
                'health_pct'     => $health_pct,
                'links_on_track' => $links_on_track,
                'total_links'    => $total_links,
                'links_delayed'  => $links_delayed,
            ];
        });

        return response()->json(['data' => $data->values()]);
    }

    /**
     * Groups every open placement under the team member responsible for it,
     * sorted by member name.
     *
     * A placement is credited to its "Assigned To" user when that user belongs
     * to the link building team. Otherwise it falls back to the "Link Builder"
     * name imported from the team spreadsheet (e.g. "1. Bringhurst, Krista").
     * Both sources are merged by display name, so one person never shows twice.
     *
     * @return Collection<int, array{team_id: string, name: string, placements: Collection}>
     */
    private function groupOpenPlacementsByTeamMember(): Collection
    {
        $team_member_names = $this->loadTeamMemberNames();

        $placements = DB::table('link_building_order_placements')
            ->whereNotIn('status', self::CLOSED_STATUSES)
            ->where(function ($q) {
                $q->whereNotNull('assigned_admin_user_id')
                  ->orWhere(fn ($q) => $q->whereNotNull('link_builder')->where('link_builder', '!=', ''));
            })
            ->get(['assigned_admin_user_id', 'link_builder', 'estimated_delivery_date', 'status']);

        return $placements
            ->map(fn ($p) => [
                'name'      => $this->resolveTeamMemberName($p, $team_member_names),
                'placement' => $p,
            ])
            ->filter(fn (array $entry) => $entry['name'] !== null)
            ->groupBy(fn (array $entry) => mb_strtolower($entry['name']))
            ->map(fn (Collection $entries, string $name_key) => [
                'team_id'    => Str::slug($name_key),
                'name'       => $entries->first()['name'],
                'placements' => $entries->pluck('placement'),
            ])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    /**
     * Returns a user_id => full name map of the link building team accounts.
     *
     * @return array<int, string>
     */
    private function loadTeamMemberNames(): array
    {
        return User::whereHas('roles', fn ($q) => $q->whereIn('name', self::TEAM_MEMBER_ROLES))
            ->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn (User $user) => [
                $user->id => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')),
            ])
            ->filter()
            ->all();
    }

    /**
     * Picks the display name of the team member responsible for a placement,
     * or null when the placement has no real team member behind it.
     */
    private function resolveTeamMemberName(object $placement, array $team_member_names): ?string
    {
        $assigned_user_id = $placement->assigned_admin_user_id;

        if ($assigned_user_id !== null && isset($team_member_names[$assigned_user_id])) {
            return $team_member_names[$assigned_user_id];
        }

        return $this->formatLinkBuilderName($placement->link_builder);
    }

    /**
     * Turns a spreadsheet "Link Builder" value such as "2. Anderson, Kaitlin"
     * into a display name such as "Kaitlin Anderson".
     */
    private function formatLinkBuilderName(?string $link_builder): ?string
    {
        // Drop the leading team number ("2. ", "2) ", "2 - ")
        $name = preg_replace('/^\s*\d+\s*[.)\-]?\s*/', '', (string) $link_builder);

        if (str_contains($name, ',')) {
            [$last_name, $first_name] = array_map('trim', explode(',', $name, 2));
            $name = "{$first_name} {$last_name}";
        }

        $name = trim(preg_replace('/\s+/', ' ', $name));

        return $name !== '' ? $name : null;
    }

    /**
     * Determines whether a link building placement is delayed.
     * A placement is delayed when estimated_delivery_date is in the past
     * and it is not yet Live or Cancelled.
     */
    private function isDelayed(?string $estimated_delivery_date, string $status, Carbon $today): bool
    {
        if (in_array($status, self::CLOSED_STATUSES, true)) {
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
