<?php

namespace Tests\Unit\Models;

use App\Models\Discount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Discount\InteractsWithBulkDiscounts;
use Tests\TestCase;

/**
 * Discount model pieces behind "promotions never stack": the
 * allow_promo_code_stacking attribute, the active / forProductType scopes,
 * Discount::allowsPromoCodeStacking and Discount::resolveBulkDiscount.
 */
class DiscountPromoCodeStackingTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithBulkDiscounts;

    // ── Attribute ────────────────────────────────────────────────────────────

    public function test_a_new_discount_does_not_allow_stacking_before_it_is_saved(): void
    {
        $this->assertFalse((new Discount())->allow_promo_code_stacking);
    }

    public function test_a_saved_discount_does_not_allow_stacking_by_default(): void
    {
        $discount = $this->makeBulkDiscount();

        $this->assertFalse($discount->fresh()->allow_promo_code_stacking);
    }

    public function test_the_option_is_cast_to_a_boolean(): void
    {
        $discount = $this->makeBulkDiscount(['allow_promo_code_stacking' => 1]);

        $this->assertSame(true, $discount->fresh()->allow_promo_code_stacking);
    }

    public function test_the_option_is_mass_assignable(): void
    {
        $this->assertContains('allow_promo_code_stacking', (new Discount())->getFillable());
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    public function test_active_scope_skips_inactive_discounts(): void
    {
        $active = $this->makeBulkDiscount();
        $this->makeBulkDiscount(['name' => 'Old bulk', 'is_active' => false]);

        $this->assertEquals([$active->id], Discount::query()->active()->pluck('id')->all());
    }

    public function test_product_type_scope_includes_discounts_for_all_products(): void
    {
        $link_building = $this->makeBulkDiscount();
        $all_products  = $this->makeBulkDiscount(['name' => 'Store wide', 'applies_to' => Discount::APPLIES_TO_ALL]);
        $this->makeBulkDiscount(['name' => 'Content bulk', 'applies_to' => 'new_content']);

        $ids = Discount::query()->forProductType('link_building')->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$link_building->id, $all_products->id], $ids);
    }

    // ── allowsPromoCodeStacking ──────────────────────────────────────────────

    public function test_stacking_is_not_allowed_when_no_discount_exists(): void
    {
        $this->assertFalse(Discount::allowsPromoCodeStacking('link_building'));
    }

    public function test_stacking_is_not_allowed_when_the_discount_keeps_the_default(): void
    {
        $this->makeBulkDiscount();

        $this->assertFalse(Discount::allowsPromoCodeStacking('link_building'));
    }

    public function test_stacking_is_allowed_when_the_discount_opts_in(): void
    {
        $this->makeStackableBulkDiscount();

        $this->assertTrue(Discount::allowsPromoCodeStacking('link_building'));
    }

    public function test_stacking_is_allowed_when_every_matching_discount_opts_in(): void
    {
        $this->makeStackableBulkDiscount();
        $this->makeStackableBulkDiscount(['name' => 'Store wide', 'applies_to' => Discount::APPLIES_TO_ALL]);

        $this->assertTrue(Discount::allowsPromoCodeStacking('link_building'));
    }

    public function test_one_matching_discount_that_does_not_opt_in_blocks_stacking(): void
    {
        $this->makeStackableBulkDiscount();
        $this->makeBulkDiscount(['name' => 'Store wide', 'applies_to' => Discount::APPLIES_TO_ALL]);

        $this->assertFalse(Discount::allowsPromoCodeStacking('link_building'));
    }

    public function test_a_store_wide_discount_that_opts_in_covers_every_product_type(): void
    {
        $this->makeStackableBulkDiscount(['applies_to' => Discount::APPLIES_TO_ALL]);

        $this->assertTrue(Discount::allowsPromoCodeStacking('link_building'));
        $this->assertTrue(Discount::allowsPromoCodeStacking('new_content'));
    }

    public function test_inactive_discounts_are_ignored(): void
    {
        $this->makeStackableBulkDiscount();
        $this->makeBulkDiscount(['name' => 'Old bulk', 'is_active' => false]);

        $this->assertTrue(Discount::allowsPromoCodeStacking('link_building'));
    }

    public function test_an_inactive_discount_alone_never_allows_stacking(): void
    {
        $this->makeStackableBulkDiscount(['is_active' => false]);

        $this->assertFalse(Discount::allowsPromoCodeStacking('link_building'));
    }

    public function test_discounts_of_other_product_types_are_ignored(): void
    {
        $this->makeStackableBulkDiscount();
        $this->makeBulkDiscount(['name' => 'Content bulk', 'applies_to' => 'new_content']);

        $this->assertTrue(Discount::allowsPromoCodeStacking('link_building'));
        $this->assertFalse(Discount::allowsPromoCodeStacking('new_content'));
    }

    // ── resolveBulkDiscount ──────────────────────────────────────────────────

    public function test_bulk_discount_applies_without_a_promo_code(): void
    {
        $this->assertSame(400.0, Discount::resolveBulkDiscount(400.0, false, false));
        $this->assertSame(400.0, Discount::resolveBulkDiscount(400.0, false, true));
    }

    public function test_a_promo_code_removes_the_bulk_discount_by_default(): void
    {
        $this->assertSame(0.0, Discount::resolveBulkDiscount(400.0, true, false));
    }

    public function test_a_promo_code_keeps_the_bulk_discount_when_combining_is_allowed(): void
    {
        $this->assertSame(400.0, Discount::resolveBulkDiscount(400.0, true, true));
    }

    public function test_no_bulk_discount_stays_at_zero(): void
    {
        $this->assertSame(0.0, Discount::resolveBulkDiscount(0.0, true, true));
        $this->assertSame(0.0, Discount::resolveBulkDiscount(0.0, false, false));
    }
}
