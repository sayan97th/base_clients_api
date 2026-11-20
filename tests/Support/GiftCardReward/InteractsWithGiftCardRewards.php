<?php

namespace Tests\Support\GiftCardReward;

use App\Models\Coupon;
use App\Models\GiftCardRewardClaim;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Shared setup of the money back promo (HAPPYHOLIDAYS) tests: roles, users,
 * the reward coupon and claims created directly in the database.
 */
trait InteractsWithGiftCardRewards
{
    protected function seedGiftCardRewardRoles(): void
    {
        foreach (['client' => 'Client', 'admin' => 'Admin', 'staff' => 'Staff', 'super_admin' => 'Super Admin'] as $name => $display_name) {
            Role::firstOrCreate(['name' => $name], [
                'display_name' => $display_name,
                'description'  => $display_name,
            ]);
        }
    }

    protected function makeUserWithRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge(['is_active' => true], $attributes));
        $user->assignRole($role);

        return $user;
    }

    protected function makeRewardCoupon(array $overrides = []): Coupon
    {
        return Coupon::create(array_merge([
            'code'           => 'HAPPYHOLIDAYS',
            'name'           => 'Happy Holidays 10% Back',
            'discount_type'  => Coupon::DISCOUNT_TYPE_GIFT_CARD_REWARD,
            'discount_value' => 10.0,
            'applies_to'     => 'all',
            'is_active'      => true,
            'times_used'     => 0,
            'expires_at'     => now()->addMonth(),
        ], $overrides));
    }

    protected function makeRegularCoupon(array $overrides = []): Coupon
    {
        return Coupon::create(array_merge([
            'code'           => 'SAVE10',
            'name'           => 'Save 10%',
            'discount_type'  => 'percentage',
            'discount_value' => 10.0,
            'applies_to'     => 'all',
            'is_active'      => true,
            'times_used'     => 0,
        ], $overrides));
    }

    /**
     * Creates a claim straight in the database, without going through checkout.
     */
    protected function makeClaim(User $user, Coupon $coupon, array $overrides = []): GiftCardRewardClaim
    {
        return GiftCardRewardClaim::create(array_merge([
            'coupon_id'         => $coupon->id,
            'user_id'           => $user->id,
            'session_id'        => (string) Str::uuid(),
            'order_ids'         => [(string) Str::uuid()],
            'product_types'     => ['link_building'],
            'coupon_code'       => $coupon->code,
            'order_total'       => 800.0,
            'reward_percentage' => (float) $coupon->discount_value,
            'reward_amount'     => 80.0,
            'status'            => GiftCardRewardClaim::STATUS_PENDING_DETAILS,
        ], $overrides));
    }

    protected function makeSubmittedClaim(User $user, Coupon $coupon, array $overrides = []): GiftCardRewardClaim
    {
        return $this->makeClaim($user, $coupon, array_merge([
            'recipient_email'   => 'gifts@example.com',
            'gift_card_brand'   => 'amazon',
            'submission_source' => GiftCardRewardClaim::SOURCE_CHECKOUT,
            'status'            => GiftCardRewardClaim::STATUS_SUBMITTED,
            'submitted_at'      => now(),
        ], $overrides));
    }
}
