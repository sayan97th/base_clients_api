<?php

namespace Tests\Unit\Services;

use App\Models\LinkBuildingOrderPlacement;
use App\Models\LinkBuildingPartnerMetric;
use App\Services\LinkBuildingPartnerMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The lookup rule shared by the bulk partnership metrics update and by single
 * row edits: which columns get a value, which become SITE REMOVED and which are
 * left alone.
 */
class LinkBuildingPartnerMetricsServiceTest extends TestCase
{
    use RefreshDatabase;

    private LinkBuildingPartnerMetricsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new LinkBuildingPartnerMetricsService();
    }

    private function reference(array $overrides = []): LinkBuildingPartnerMetric
    {
        return LinkBuildingPartnerMetric::create(array_merge([
            'domain_key'        => 'techkaya.com',
            'domain'            => 'techkaya.com',
            'dr'                => '74',
            'traffic'           => '2300',
            'current_poc'       => 'David',
            'current_price'     => '$20',
            'metrics_synced_at' => now(),
            'partner_synced_at' => now(),
        ], $overrides));
    }

    private function placement(array $overrides = []): LinkBuildingOrderPlacement
    {
        return LinkBuildingOrderPlacement::create(array_merge([
            'order_id'        => 'BL-' . rand(10000, 99999),
            'client'          => 'Client',
            'keyword'         => 'keyword',
            'landing_page'    => 'https://example.com',
            'link_type'       => 'DR 30+ External',
            'status'          => 'New Request',
            'partnership'     => 'https://old-site.com',
            'dr_formula'      => 'old-dr',
            'current_traffic' => 'old-traffic',
            'current_poc'     => 'old-poc',
            'current_price'   => 'old-price',
        ], $overrides));
    }

    // ── resolvePlacementValues ─────────────────────────────────────────────────

    public function test_listed_partner_with_metrics_fills_every_column(): void
    {
        $values = $this->service->resolvePlacementValues(
            $this->reference(),
            LinkBuildingPartnerMetricsService::PLACEMENT_COLUMNS,
            has_metrics_reference: true,
            has_partner_list: true,
            clear_missing_metrics: false,
        );

        $this->assertSame([
            'dr_formula'      => '74',
            'current_traffic' => '2300',
            'current_poc'     => 'David',
            'current_price'   => '$20',
        ], $values);
    }

    public function test_only_the_requested_columns_are_returned(): void
    {
        $values = $this->service->resolvePlacementValues(
            $this->reference(),
            ['current_price'],
            has_metrics_reference: true,
            has_partner_list: true,
            clear_missing_metrics: false,
        );

        $this->assertSame(['current_price' => '$20'], $values);
    }

    public function test_unknown_domain_is_site_removed_once_a_partner_list_exists(): void
    {
        $values = $this->service->resolvePlacementValues(
            null,
            LinkBuildingPartnerMetricsService::PLACEMENT_COLUMNS,
            has_metrics_reference: true,
            has_partner_list: true,
            clear_missing_metrics: false,
        );

        $this->assertSame([
            'current_poc'   => LinkBuildingPartnerMetric::SITE_REMOVED,
            'current_price' => LinkBuildingPartnerMetric::SITE_REMOVED,
        ], $values);
    }

    public function test_domain_that_left_the_partner_list_is_site_removed_but_keeps_its_metrics(): void
    {
        $reference = $this->reference(['current_poc' => null, 'current_price' => null, 'partner_synced_at' => null]);

        $values = $this->service->resolvePlacementValues(
            $reference,
            LinkBuildingPartnerMetricsService::PLACEMENT_COLUMNS,
            has_metrics_reference: true,
            has_partner_list: true,
            clear_missing_metrics: false,
        );

        $this->assertSame('74', $values['dr_formula']);
        $this->assertSame(LinkBuildingPartnerMetric::SITE_REMOVED, $values['current_poc']);
        $this->assertSame(LinkBuildingPartnerMetric::SITE_REMOVED, $values['current_price']);
    }

    public function test_nothing_is_flagged_while_no_partner_list_was_uploaded(): void
    {
        $values = $this->service->resolvePlacementValues(
            null,
            LinkBuildingPartnerMetricsService::PARTNER_COLUMNS,
            has_metrics_reference: false,
            has_partner_list: false,
            clear_missing_metrics: true,
        );

        $this->assertSame([], $values);
    }

    public function test_missing_metrics_are_kept_unless_clearing_is_requested(): void
    {
        $reference = $this->reference(['dr' => null, 'traffic' => null, 'metrics_synced_at' => null]);

        $kept = $this->service->resolvePlacementValues(
            $reference,
            LinkBuildingPartnerMetricsService::METRIC_COLUMNS,
            has_metrics_reference: true,
            has_partner_list: true,
            clear_missing_metrics: false,
        );
        $cleared = $this->service->resolvePlacementValues(
            $reference,
            LinkBuildingPartnerMetricsService::METRIC_COLUMNS,
            has_metrics_reference: true,
            has_partner_list: true,
            clear_missing_metrics: true,
        );

        $this->assertSame([], $kept);
        $this->assertSame(['dr_formula' => null, 'current_traffic' => null], $cleared);
    }

    public function test_metrics_are_never_cleared_while_no_metrics_were_uploaded(): void
    {
        $values = $this->service->resolvePlacementValues(
            null,
            LinkBuildingPartnerMetricsService::METRIC_COLUMNS,
            has_metrics_reference: false,
            has_partner_list: true,
            clear_missing_metrics: true,
        );

        $this->assertSame([], $values);
    }

    // ── hasMetricsReference / hasPartnerList ───────────────────────────────────

    public function test_reference_flags_follow_the_uploaded_data(): void
    {
        $this->assertFalse($this->service->hasMetricsReference());
        $this->assertFalse($this->service->hasPartnerList());

        $this->reference(['partner_synced_at' => null]);
        $this->assertTrue($this->service->hasMetricsReference());
        $this->assertFalse($this->service->hasPartnerList());

        $this->reference(['domain_key' => 'b.com', 'domain' => 'b.com', 'metrics_synced_at' => null]);
        $this->assertTrue($this->service->hasPartnerList());
    }

    // ── withPartnershipLookup ──────────────────────────────────────────────────

    public function test_lookup_fills_metrics_when_the_partnership_changes(): void
    {
        $this->reference();
        $placement = $this->placement();

        $attributes = $this->service->withPartnershipLookup($placement, ['partnership' => 'https://www.techkaya.com/', 'notes' => 'x']);

        $this->assertSame([
            'partnership'     => 'https://www.techkaya.com/',
            'notes'           => 'x',
            'dr_formula'      => '74',
            'current_traffic' => '2300',
            'current_poc'     => 'David',
            'current_price'   => '$20',
        ], $attributes);
    }

    public function test_lookup_overrides_stale_metrics_sent_with_a_full_row_save(): void
    {
        $this->reference();
        $placement = $this->placement();

        $attributes = $this->service->withPartnershipLookup($placement, [
            'partnership'   => 'techkaya.com',
            'current_poc'   => 'old-poc',
            'current_price' => 'old-price',
        ]);

        $this->assertSame('David', $attributes['current_poc']);
        $this->assertSame('$20', $attributes['current_price']);
    }

    public function test_given_values_win_when_requested(): void
    {
        $this->reference();
        $placement = $this->placement();

        $attributes = $this->service->withPartnershipLookup(
            $placement,
            ['partnership' => 'techkaya.com', 'current_price' => '99'],
            keep_given_values: true,
        );

        $this->assertSame('99', $attributes['current_price']);
        $this->assertSame('David', $attributes['current_poc']);
    }

    public function test_lookup_is_skipped_when_the_partnership_is_the_same_domain(): void
    {
        $this->reference();
        $placement = $this->placement(['partnership' => 'https://techkaya.com']);
        $attributes = ['partnership' => 'www.TechKaya.com/', 'current_poc' => 'manual'];

        $this->assertSame($attributes, $this->service->withPartnershipLookup($placement, $attributes));
    }

    public function test_lookup_is_skipped_when_the_partnership_is_cleared_or_not_sent(): void
    {
        $this->reference();
        $placement = $this->placement();

        $this->assertSame(['partnership' => null], $this->service->withPartnershipLookup($placement, ['partnership' => null]));
        $this->assertSame(['notes' => 'x'], $this->service->withPartnershipLookup($placement, ['notes' => 'x']));
    }

    public function test_lookup_changes_nothing_while_the_reference_list_is_empty(): void
    {
        $placement  = $this->placement();
        $attributes = ['partnership' => 'techkaya.com'];

        $this->assertSame($attributes, $this->service->withPartnershipLookup($placement, $attributes));
    }

    public function test_lookup_removes_the_formulas_of_the_filled_cells_only(): void
    {
        $this->reference();
        $placement = $this->placement([
            'cell_formulas' => [
                'current_price' => '=[dr_lbs:~0]*2',
                'final_price'   => '=[current_price:~0]*1.15',
            ],
        ]);

        $attributes = $this->service->withPartnershipLookup($placement, ['partnership' => 'techkaya.com']);

        $this->assertSame(['final_price' => '=[current_price:~0]*1.15'], $attributes['cell_formulas']);
    }

    public function test_lookup_uses_the_formulas_sent_with_the_save_over_the_stored_ones(): void
    {
        $this->reference();
        $placement = $this->placement(['cell_formulas' => ['final_price' => '=1']]);

        $attributes = $this->service->withPartnershipLookup($placement, [
            'partnership'   => 'techkaya.com',
            'cell_formulas' => ['current_poc' => '=2'],
        ]);

        $this->assertNull($attributes['cell_formulas']);
    }

    public function test_lookup_leaves_cell_formulas_out_when_no_filled_cell_had_one(): void
    {
        $this->reference();
        $placement = $this->placement(['cell_formulas' => ['final_price' => '=1']]);

        $attributes = $this->service->withPartnershipLookup($placement, ['partnership' => 'techkaya.com']);

        $this->assertArrayNotHasKey('cell_formulas', $attributes);
    }

    public function test_new_row_without_a_partnership_yet_gets_the_lookup(): void
    {
        $this->reference();

        $attributes = $this->service->withPartnershipLookup(new LinkBuildingOrderPlacement(), ['partnership' => 'techkaya.com']);

        $this->assertSame('David', $attributes['current_poc']);
    }
}
