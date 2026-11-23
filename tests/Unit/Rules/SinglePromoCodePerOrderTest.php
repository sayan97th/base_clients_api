<?php

namespace Tests\Unit\Rules;

use App\Models\Coupon;
use App\Rules\SinglePromoCodePerOrder;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class SinglePromoCodePerOrderTest extends TestCase
{
    private function validateCouponIds(mixed $coupon_ids): \Illuminate\Validation\Validator
    {
        return Validator::make(
            ['coupon_ids' => $coupon_ids],
            ['coupon_ids' => ['nullable', 'array', new SinglePromoCodePerOrder()]]
        );
    }

    public function test_no_promo_code_passes(): void
    {
        $this->assertTrue($this->validateCouponIds([])->passes());
        $this->assertTrue($this->validateCouponIds(null)->passes());
    }

    public function test_a_single_promo_code_passes(): void
    {
        $this->assertTrue($this->validateCouponIds(['11111111-1111-1111-1111-111111111111'])->passes());
    }

    public function test_the_same_promo_code_sent_twice_counts_as_one(): void
    {
        $coupon_id = '11111111-1111-1111-1111-111111111111';

        $this->assertTrue($this->validateCouponIds([$coupon_id, $coupon_id])->passes());
    }

    public function test_two_different_promo_codes_are_rejected_with_the_combination_copy(): void
    {
        $validator = $this->validateCouponIds([
            '11111111-1111-1111-1111-111111111111',
            '22222222-2222-2222-2222-222222222222',
        ]);

        $this->assertTrue($validator->fails());
        $this->assertSame(Coupon::COMBINATION_ERROR_MESSAGE, $validator->errors()->first('coupon_ids'));
        $this->assertSame('This promo cannot be combined with other offers.', Coupon::COMBINATION_ERROR_MESSAGE);
    }
}
