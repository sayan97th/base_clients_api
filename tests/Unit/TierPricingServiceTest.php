<?php

namespace Tests\Unit;

use App\Models\ContentBriefTier;
use App\Models\ContentOptimizationTier;
use App\Models\DrTier;
use App\Models\NewContentTier;
use App\Services\TierPricingService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Verifies TierPricingService always resolves the price from the tier's
 * current database record, never from a client-supplied value. This is the
 * fix for the price-desync bug where an admin price change (or a stale/
 * tampered client payload) could disagree with what checkout actually
 * charges.
 */
class TierPricingServiceTest extends TestCase
{
    use RefreshDatabase;

    private TierPricingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new TierPricingService();
    }

    // ─── resolveUnitPrice: one lookup per product type ─────────────────────────

    public function test_resolves_link_building_price_from_dr_tier(): void
    {
        DrTier::create([
            'id'             => 'dr60',
            'label'          => 'DR 60+',
            'min_dr'         => 60,
            'max_dr'         => 69,
            'traffic_range'  => '5k-50k',
            'word_count'     => 700,
            'price_per_link' => 475.0,
            'is_hidden'      => false,
            'is_active'      => true,
        ]);

        $price = $this->service->resolveUnitPrice('link_building', 'dr60');

        $this->assertSame(475.0, $price);
    }

    public function test_resolves_content_optimization_price_from_tier(): void
    {
        ContentOptimizationTier::create([
            'id'               => 'co-basic',
            'label'            => 'Basic',
            'word_count_range' => '500-1000',
            'turnaround_days'  => 5,
            'price'            => 200.0,
            'is_active'        => true,
        ]);

        $price = $this->service->resolveUnitPrice('content_optimization', 'co-basic');

        $this->assertSame(200.0, $price);
    }

    public function test_resolves_new_content_price_from_tier(): void
    {
        NewContentTier::create([
            'id'              => 'nc-standard',
            'label'           => 'Standard Article',
            'turnaround_time' => '6 Days',
            'price'           => 150.0,
            'is_active'       => true,
        ]);

        $price = $this->service->resolveUnitPrice('new_content', 'nc-standard');

        $this->assertSame(150.0, $price);
    }

    public function test_resolves_content_brief_price_from_tier(): void
    {
        ContentBriefTier::create([
            'id'              => 'cb-standard',
            'label'           => 'Standard Brief',
            'turnaround_days' => 3,
            'price'           => 80.0,
            'is_active'       => true,
        ]);

        $price = $this->service->resolveUnitPrice('content_brief', 'cb-standard');

        $this->assertSame(80.0, $price);
    }

    // ─── The whole point of the fix: DB price wins, always ─────────────────────

    public function test_resolved_price_reflects_the_current_admin_configured_value(): void
    {
        $tier = DrTier::create([
            'id'             => 'dr60',
            'label'          => 'DR 60+',
            'min_dr'         => 60,
            'max_dr'         => 69,
            'traffic_range'  => '5k-50k',
            'word_count'     => 700,
            'price_per_link' => 500.0,
            'is_hidden'      => false,
            'is_active'      => true,
        ]);

        $this->assertSame(500.0, $this->service->resolveUnitPrice('link_building', 'dr60'));

        // Admin drops the price after the client already loaded the page.
        $tier->update(['price_per_link' => 475.0]);

        $this->assertSame(475.0, $this->service->resolveUnitPrice('link_building', 'dr60'));
    }

    // ─── Missing / unknown tier ──────────────────────────────────────────────

    public function test_throws_domain_exception_when_tier_does_not_exist(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('tier_not_found:link_building:missing-tier');

        $this->service->resolveUnitPrice('link_building', 'missing-tier');
    }

    public function test_throws_domain_exception_for_soft_deleted_dr_tier(): void
    {
        $tier = DrTier::create([
            'id'             => 'dr60',
            'label'          => 'DR 60+',
            'min_dr'         => 60,
            'max_dr'         => 69,
            'traffic_range'  => '5k-50k',
            'word_count'     => 700,
            'price_per_link' => 475.0,
            'is_hidden'      => false,
            'is_active'      => true,
        ]);
        $tier->delete(); // soft delete

        $this->expectException(DomainException::class);

        $this->service->resolveUnitPrice('link_building', 'dr60');
    }

    public function test_throws_invalid_argument_exception_for_unknown_product_type(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->resolveUnitPrice('not_a_real_product', 'whatever');
    }

    // ─── resolveItemPrices: overrides every item, ignoring client unit_price ──

    public function test_resolve_item_prices_overrides_link_building_items_keyed_on_dr_tier_id(): void
    {
        DrTier::create([
            'id'             => 'dr60',
            'label'          => 'DR 60+',
            'min_dr'         => 60,
            'max_dr'         => 69,
            'traffic_range'  => '5k-50k',
            'word_count'     => 700,
            'price_per_link' => 475.0,
            'is_hidden'      => false,
            'is_active'      => true,
        ]);

        $items = [
            ['dr_tier_id' => 'dr60', 'quantity' => 11, 'unit_price' => 500.0],
        ];

        $resolved = $this->service->resolveItemPrices('link_building', $items);

        $this->assertSame(475.0, $resolved[0]['unit_price']);
        $this->assertSame(11, $resolved[0]['quantity']);
    }

    public function test_resolve_item_prices_overrides_non_link_building_items_keyed_on_tier_id(): void
    {
        ContentBriefTier::create([
            'id'              => 'cb-standard',
            'label'           => 'Standard Brief',
            'turnaround_days' => 3,
            'price'           => 80.0,
            'is_active'       => true,
        ]);

        $items = [
            ['tier_id' => 'cb-standard', 'quantity' => 2, 'unit_price' => 9999.0],
        ];

        $resolved = $this->service->resolveItemPrices('content_brief', $items);

        $this->assertSame(80.0, $resolved[0]['unit_price']);
    }

    public function test_resolve_item_prices_handles_multiple_items_with_different_tiers(): void
    {
        DrTier::create([
            'id' => 'dr30', 'label' => 'DR 30+', 'min_dr' => 30, 'max_dr' => 39,
            'traffic_range' => '1k-5k', 'word_count' => 500,
            'price_per_link' => 260.0, 'is_hidden' => false, 'is_active' => true,
        ]);
        DrTier::create([
            'id' => 'dr60', 'label' => 'DR 60+', 'min_dr' => 60, 'max_dr' => 69,
            'traffic_range' => '5k-50k', 'word_count' => 700,
            'price_per_link' => 475.0, 'is_hidden' => false, 'is_active' => true,
        ]);

        $items = [
            ['dr_tier_id' => 'dr30', 'quantity' => 2, 'unit_price' => 1.0],
            ['dr_tier_id' => 'dr60', 'quantity' => 3, 'unit_price' => 1.0],
        ];

        $resolved = $this->service->resolveItemPrices('link_building', $items);

        $this->assertSame(260.0, $resolved[0]['unit_price']);
        $this->assertSame(475.0, $resolved[1]['unit_price']);
    }

    public function test_resolve_item_prices_preserves_other_item_fields(): void
    {
        ContentOptimizationTier::create([
            'id'               => 'co-basic',
            'label'            => 'Basic',
            'word_count_range' => '500-1000',
            'turnaround_days'  => 5,
            'price'            => 200.0,
            'is_active'        => true,
        ]);

        $items = [
            [
                'tier_id'     => 'co-basic',
                'quantity'    => 4,
                'unit_price'  => 1.0,
                'intake_rows' => [['primary_keyword' => 'test']],
            ],
        ];

        $resolved = $this->service->resolveItemPrices('content_optimization', $items);

        $this->assertSame(200.0, $resolved[0]['unit_price']);
        $this->assertSame(4, $resolved[0]['quantity']);
        $this->assertSame([['primary_keyword' => 'test']], $resolved[0]['intake_rows']);
    }
}
