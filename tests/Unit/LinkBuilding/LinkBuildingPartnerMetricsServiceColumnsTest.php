<?php

namespace Tests\Unit\LinkBuilding;

use App\Models\LinkBuildingPartnerMetric;
use App\Services\LinkBuildingPartnerMetricsService;
use Tests\TestCase;

/**
 * The partnership lookup fills exactly the columns the dashboard colors as
 * "Traffic and Pricing" (Current Traffic, DR Formula, Current POC, Current Price).
 * DR and Posting Fee are entered by Link Builders, so the lookup never writes them.
 */
class LinkBuildingPartnerMetricsServiceColumnsTest extends TestCase
{
    private const LINK_BUILDER_COLUMNS = ['dr_lbs', 'posting_fee_lbs'];

    public function test_lookup_columns_are_the_four_traffic_and_pricing_columns(): void
    {
        $this->assertEqualsCanonicalizing(
            ['current_traffic', 'dr_formula', 'current_poc', 'current_price'],
            LinkBuildingPartnerMetricsService::PLACEMENT_COLUMNS
        );
    }

    public function test_lookup_columns_never_include_the_link_builder_columns(): void
    {
        foreach (self::LINK_BUILDER_COLUMNS as $column) {
            $this->assertNotContains($column, LinkBuildingPartnerMetricsService::PLACEMENT_COLUMNS);
        }
    }

    public function test_metric_and_partner_columns_split_the_lookup_columns_without_overlap(): void
    {
        $this->assertEmpty(array_intersect(
            LinkBuildingPartnerMetricsService::METRIC_COLUMNS,
            LinkBuildingPartnerMetricsService::PARTNER_COLUMNS
        ));
        $this->assertEqualsCanonicalizing(
            LinkBuildingPartnerMetricsService::PLACEMENT_COLUMNS,
            [...LinkBuildingPartnerMetricsService::METRIC_COLUMNS, ...LinkBuildingPartnerMetricsService::PARTNER_COLUMNS]
        );
    }

    public function test_resolved_values_only_contain_traffic_and_pricing_columns(): void
    {
        $reference = new LinkBuildingPartnerMetric([
            'domain_key'    => 'techkaya.com',
            'domain'        => 'techkaya.com',
            'dr'            => '74',
            'traffic'       => '2300',
            'current_poc'   => 'David',
            'current_price' => '$20',
        ]);
        $reference->metrics_synced_at = now();
        $reference->partner_synced_at = now();

        $values = (new LinkBuildingPartnerMetricsService())->resolvePlacementValues(
            $reference,
            LinkBuildingPartnerMetricsService::PLACEMENT_COLUMNS,
            has_metrics_reference: true,
            has_partner_list: true,
            clear_missing_metrics: true,
        );

        $this->assertSame([
            'dr_formula'      => '74',
            'current_traffic' => '2300',
            'current_poc'     => 'David',
            'current_price'   => '$20',
        ], $values);
        foreach (self::LINK_BUILDER_COLUMNS as $column) {
            $this->assertArrayNotHasKey($column, $values);
        }
    }
}
