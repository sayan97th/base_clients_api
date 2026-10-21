<?php

namespace App\Services;

use App\Models\LinkBuildingOrderPlacement;
use App\Models\LinkBuildingPartnerMetric;

/**
 * Looks up the Partnership of a Link Table row in the partner metrics reference
 * list, the portal version of the BASE Google Sheet formulas:
 *
 *   DR Formula      = VLOOKUP(Partnership, 'SEO Metrics', DR)
 *   Current Traffic = VLOOKUP(Partnership, 'SEO Metrics', Traffic)
 *   Current POC     = IFNA(VLOOKUP(Partnership, 'Current POC', POC), "SITE REMOVED")
 *   Current Price   = IFNA(VLOOKUP(Partnership, 'Current POC', Price), "SITE REMOVED")
 *
 * Used by the bulk partnership metrics update and whenever an admin changes the
 * Partnership of a row, so a newly chosen site shows its current metrics, POC
 * and price right away.
 */
class LinkBuildingPartnerMetricsService
{
    /** Link Table columns filled from the reference list. */
    public const PLACEMENT_COLUMNS = ['dr_formula', 'current_traffic', 'current_poc', 'current_price'];

    /** Link Table columns that come from the metrics list (DR and traffic). */
    public const METRIC_COLUMNS = ['dr_formula', 'current_traffic'];

    /** Link Table columns that come from the partner list (POC and price). */
    public const PARTNER_COLUMNS = ['current_poc', 'current_price'];

    public function hasMetricsReference(): bool
    {
        return LinkBuildingPartnerMetric::whereNotNull('metrics_synced_at')->exists();
    }

    public function hasPartnerList(): bool
    {
        return LinkBuildingPartnerMetric::whereNotNull('partner_synced_at')->exists();
    }

    /**
     * Values to write on a Link Table row for the given columns, keyed by column.
     * A column is left out when there is nothing reliable to write:
     *
     * - DR Formula and Current Traffic take the reference values. When the domain
     *   has no metrics, they are cleared only if $clear_missing_metrics is true.
     * - Current POC and Current Price take the reference values, or SITE REMOVED
     *   when the domain is not in the partner list. Nothing is written while no
     *   partner list has been uploaded yet, so every row is not flagged at once.
     *
     * @param  string[]  $columns  Subset of PLACEMENT_COLUMNS.
     * @return array<string, string|null>
     */
    public function resolvePlacementValues(
        ?LinkBuildingPartnerMetric $reference,
        array $columns,
        bool $has_metrics_reference,
        bool $has_partner_list,
        bool $clear_missing_metrics,
    ): array {
        $values = [];

        foreach (array_intersect(self::METRIC_COLUMNS, $columns) as $column) {
            if ($reference?->hasMetrics()) {
                $values[$column] = $column === 'dr_formula' ? $reference->dr : $reference->traffic;
            } elseif ($clear_missing_metrics && $has_metrics_reference) {
                $values[$column] = null;
            }
        }

        foreach (array_intersect(self::PARTNER_COLUMNS, $columns) as $column) {
            if ($reference?->isListedPartner()) {
                $values[$column] = $reference->{$column};
            } elseif ($has_partner_list) {
                $values[$column] = LinkBuildingPartnerMetric::SITE_REMOVED;
            }
        }

        return $values;
    }

    /**
     * Adds the looked up metrics to the attributes about to be saved on a row when
     * they change its Partnership to a different domain. Metrics of a domain with
     * no reference values are cleared, since the old ones belong to another site.
     * The filled cells lose their spreadsheet formulas, like any value written
     * over a formula cell.
     *
     * @param  array<string, mixed>  $attributes  Validated attributes for update()/create().
     * @param  bool  $keep_given_values  When true, metric columns already present in
     *                                   $attributes win over the lookup. Used by the
     *                                   per-cell endpoints, where a pasted block can set
     *                                   the Partnership and its metrics together. Full
     *                                   row saves always send every column, so they
     *                                   leave it off.
     * @return array<string, mixed>
     */
    public function withPartnershipLookup(LinkBuildingOrderPlacement $placement, array $attributes, bool $keep_given_values = false): array
    {
        if (! array_key_exists('partnership', $attributes)) {
            return $attributes;
        }

        $new_key = LinkBuildingPartnerMetric::normalizeDomainKey($attributes['partnership']);
        $old_key = LinkBuildingPartnerMetric::normalizeDomainKey($placement->partnership);

        if ($new_key === null || $new_key === $old_key) {
            return $attributes;
        }

        $values = $this->resolvePlacementValues(
            LinkBuildingPartnerMetric::where('domain_key', $new_key)->first(),
            self::PLACEMENT_COLUMNS,
            $this->hasMetricsReference(),
            $this->hasPartnerList(),
            clear_missing_metrics: true,
        );

        if ($keep_given_values) {
            $values = array_diff_key($values, $attributes);
        }

        if (empty($values)) {
            return $attributes;
        }

        $cell_formulas = array_key_exists('cell_formulas', $attributes)
            ? $attributes['cell_formulas']
            : $placement->cell_formulas;

        if (is_array($cell_formulas) && ! empty(array_intersect_key($cell_formulas, $values))) {
            $remaining                   = array_diff_key($cell_formulas, $values);
            $attributes['cell_formulas'] = empty($remaining) ? null : $remaining;
        }

        return [...$attributes, ...$values];
    }
}
