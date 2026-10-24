<?php

namespace App\Services;

use App\Models\LinkBuildingOrderPlacement;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Flags the rows of the admin "Link Building Orders" table whose Partnership
 * domain was already used for the same Landing Page in the months before the
 * row's Request Date, so the dashboard can highlight the domain in red and the
 * writer picks a different site instead of linking to the same page twice.
 *
 * Domains and landing pages are compared by their normalized keys (see
 * LinkBuildingOrderPlacement::usageKeysFor()), so "https://www.site.com/" and
 * "site.com" count as the same site. Orders that never went ahead (cancelled,
 * rejected or not approved) do not count as a use.
 */
class LinkBuildingDomainUsageService
{
    /** How far back, from a row's Request Date, an earlier order of the same domain and page counts. */
    public const WINDOW_MONTHS = 3;

    /** Statuses of orders that never placed a link, so they neither count as a use nor get flagged. */
    public const IGNORED_STATUSES = ['Cancelled', 'Rejected', 'Not Approved'];

    /**
     * Sets the recent domain usage of every given placement (null when there is
     * none), with a single query for all of them, so a whole page of the dashboard
     * is annotated without one query per row.
     *
     * @param  iterable<LinkBuildingOrderPlacement>  $placements
     */
    public function annotate(iterable $placements): void
    {
        $placements = Collection::wrap($placements);

        $candidates = $placements->filter(fn (LinkBuildingOrderPlacement $p) => $this->isTracked($p));

        $placements->each(fn (LinkBuildingOrderPlacement $p) => $p->setRecentDomainUsage(null));

        if ($candidates->isEmpty()) {
            return;
        }

        $usages_by_pair = $this->usagesFor($candidates)->groupBy(
            fn (LinkBuildingOrderPlacement $usage) => $this->pairKey($usage),
        );

        foreach ($candidates as $placement) {
            $placement->setRecentDomainUsage(
                $this->recentUsage($placement, $usages_by_pair->get($this->pairKey($placement), collect())),
            );
        }
    }

    /**
     * Every order that used one of the domain and landing page pairs of the given
     * placements, whatever its date. The composite index on the two keys keeps it fast.
     *
     * @param  Collection<int, LinkBuildingOrderPlacement>  $placements
     * @return Collection<int, LinkBuildingOrderPlacement>
     */
    private function usagesFor(Collection $placements): Collection
    {
        $pair_keys = $placements->map(fn (LinkBuildingOrderPlacement $p) => $this->pairKey($p))->flip();

        return LinkBuildingOrderPlacement::query()
            ->select(['id', 'order_id', 'partnership_key', 'landing_page_key', 'request_date', 'status', 'created_at'])
            ->whereIn('partnership_key', $placements->pluck('partnership_key')->unique()->values())
            ->whereIn('landing_page_key', $placements->pluck('landing_page_key')->unique()->values())
            ->where(function ($query) {
                $query->whereNull('status')->orWhereNotIn('status', self::IGNORED_STATUSES);
            })
            ->get()
            // Both whereIn lists together also match crossed pairs (domain of one row,
            // landing page of another), which are dropped here.
            ->filter(fn (LinkBuildingOrderPlacement $usage) => $pair_keys->has($this->pairKey($usage)))
            ->values();
    }

    /**
     * The other orders of the same domain and landing page dated within the window
     * that ends on the placement's own date, summarized for the dashboard tooltip.
     *
     * @param  Collection<int, LinkBuildingOrderPlacement>  $usages
     * @return array{count: int, last_order_id: string, last_request_date: string, window_months: int}|null
     */
    private function recentUsage(LinkBuildingOrderPlacement $placement, Collection $usages): ?array
    {
        $reference_date = $this->usageDate($placement);
        $window_start   = $reference_date->copy()->subMonthsNoOverflow(self::WINDOW_MONTHS);

        $recent = $usages
            ->reject(fn (LinkBuildingOrderPlacement $usage) => $usage->id === $placement->id)
            ->map(fn (LinkBuildingOrderPlacement $usage) => ['usage' => $usage, 'date' => $this->usageDate($usage)])
            ->filter(fn (array $entry) => $entry['date']->betweenIncluded($window_start, $reference_date))
            ->sortByDesc(fn (array $entry) => $entry['date']->timestamp)
            ->values();

        if ($recent->isEmpty()) {
            return null;
        }

        $last = $recent->first();

        return [
            'count'             => $recent->count(),
            'last_order_id'     => $last['usage']->displayOrderId(),
            'last_request_date' => $last['date']->format('m/d/Y'),
            'window_months'     => self::WINDOW_MONTHS,
        ];
    }

    /** True when the row has both a domain and a landing page and its order is still going ahead. */
    private function isTracked(LinkBuildingOrderPlacement $placement): bool
    {
        return filled($placement->partnership_key)
            && filled($placement->landing_page_key)
            && ! in_array($placement->status, self::IGNORED_STATUSES, true);
    }

    /**
     * Date an order used its domain: its Request Date (MM/DD/YYYY), or the day the
     * row was created when the request date is empty or not a valid date. A row
     * not saved yet uses today.
     */
    private function usageDate(LinkBuildingOrderPlacement $placement): Carbon
    {
        $request_date = trim((string) $placement->request_date);

        if ($request_date !== '') {
            try {
                return Carbon::createFromFormat('!m/d/Y', $request_date);
            } catch (\Exception) {
                // Falls back to the creation date below.
            }
        }

        return ($placement->created_at ?? Carbon::today())->copy()->startOfDay();
    }

    private function pairKey(LinkBuildingOrderPlacement $placement): string
    {
        return $placement->partnership_key . '|' . $placement->landing_page_key;
    }
}
