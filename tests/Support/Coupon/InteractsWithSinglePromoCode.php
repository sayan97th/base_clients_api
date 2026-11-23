<?php

namespace Tests\Support\Coupon;

use App\Models\Coupon;
use App\Models\DrTier;
use Illuminate\Support\Str;

/**
 * Shared setup of the "one promo code per order" tests: promo codes, a DR
 * tier to buy links from and the payload pieces every checkout needs.
 *
 * Use together with Tests\Support\GiftCardReward\InteractsWithGiftCardRewards
 * for roles, users and the HAPPYHOLIDAYS reward coupon.
 */
trait InteractsWithSinglePromoCode
{
    protected function makeDrTier(): DrTier
    {
        return DrTier::create([
            'id'             => 'dr50',
            'label'          => 'DR 50+',
            'min_dr'         => 50,
            'max_dr'         => 59,
            'traffic_range'  => '10k-20k',
            'word_count'     => 500,
            'price_per_link' => 400.0,
            'is_hidden'      => false,
            'is_active'      => true,
        ]);
    }

    protected function makePromoCode(string $code, float $percentage = 10.0, array $overrides = []): Coupon
    {
        return Coupon::create(array_merge([
            'code'           => $code,
            'name'           => "{$code} promo",
            'discount_type'  => Coupon::DISCOUNT_TYPE_PERCENTAGE,
            'discount_value' => $percentage,
            'applies_to'     => Coupon::APPLIES_TO_ALL,
            'is_active'      => true,
            'times_used'     => 0,
        ], $overrides));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function linkBuildingItems(string $dr_tier_id, int $quantity = 2): array
    {
        return [[
            'dr_tier_id' => $dr_tier_id,
            'quantity'   => $quantity,
            'unit_price' => 400.0,
            'placements' => [[
                'row_index'    => 0,
                'keyword'      => 'promo keyword',
                'landing_page' => 'https://example.com/page',
                'exact_match'  => false,
            ]],
        ]];
    }

    /**
     * @return array<string, string>
     */
    protected function billingDetails(): array
    {
        return [
            'company'     => 'Test Co',
            'address'     => '123 Main St',
            'city'        => 'Boise',
            'state'       => 'ID',
            'country'     => 'US',
            'postal_code' => '83701',
        ];
    }

    /**
     * Saved cart entry for one promo code, as the portal sends it to PUT /api/cart.
     *
     * @return array<string, mixed>
     */
    protected function savedCartCoupon(string $code, string $discount_type = Coupon::DISCOUNT_TYPE_PERCENTAGE): array
    {
        return [
            'coupon_id'       => (string) Str::uuid(),
            'code'            => $code,
            'coupon_name'     => "{$code} promo",
            'discount_amount' => 0,
            'discount_type'   => $discount_type,
            'discount_value'  => 10,
        ];
    }
}
