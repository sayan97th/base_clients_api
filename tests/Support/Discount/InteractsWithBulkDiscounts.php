<?php

namespace Tests\Support\Discount;

use App\Models\Discount;

/**
 * Shared setup of the automatic discount tests (Bulk Link Building Discount)
 * and the "allow_promo_code_stacking" option.
 */
trait InteractsWithBulkDiscounts
{
    protected function makeBulkDiscount(array $overrides = []): Discount
    {
        return Discount::create(array_merge([
            'name'          => 'Bulk Link Building Discount',
            'description'   => '10% off when ordering 10 links or more.',
            'discount_type' => 'bulk',
            'discount_rate' => 10.0,
            'min_quantity'  => 10,
            'applies_to'    => 'link_building',
            'is_active'     => true,
        ], $overrides));
    }

    protected function makeStackableBulkDiscount(array $overrides = []): Discount
    {
        return $this->makeBulkDiscount(array_merge(['allow_promo_code_stacking' => true], $overrides));
    }
}
