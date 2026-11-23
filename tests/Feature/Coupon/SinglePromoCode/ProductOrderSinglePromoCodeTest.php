<?php

namespace Tests\Feature\Coupon\SinglePromoCode;

use App\Models\Coupon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Coupon\InteractsWithSinglePromoCode;
use Tests\Support\GiftCardReward\InteractsWithGiftCardRewards;
use Tests\TestCase;

/**
 * One promo code per order on every single product order endpoint.
 *
 * These endpoints validate the request before touching payments or orders,
 * so the rule is checked at the validation layer: stacked promo codes always
 * return the combination copy on coupon_ids, a single code never does.
 */
class ProductOrderSinglePromoCodeTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithGiftCardRewards;
    use InteractsWithSinglePromoCode;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Queue::fake();
        $this->seedGiftCardRewardRoles();
        $this->client = $this->makeUserWithRole('client');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function productOrderEndpoints(): array
    {
        return [
            'link building'        => ['/api/link-building/orders'],
            'new content'          => ['/api/new-content/orders'],
            'content briefs'       => ['/api/content-briefs/orders'],
            'content optimization' => ['/api/content-optimization/orders'],
            'premium mentions'     => ['/api/premium-mentions/orders'],
        ];
    }

    #[DataProvider('productOrderEndpoints')]
    public function test_two_promo_codes_cannot_be_combined(string $endpoint): void
    {
        $first  = $this->makePromoCode('SAVE10');
        $second = $this->makePromoCode('SAVE20', 20.0);

        $this->actingAs($this->client, 'api')
            ->postJson($endpoint, ['coupon_ids' => [$first->id, $second->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['coupon_ids' => Coupon::COMBINATION_ERROR_MESSAGE]);

        $this->assertSame(0, $first->fresh()->times_used);
        $this->assertSame(0, $second->fresh()->times_used);
    }

    #[DataProvider('productOrderEndpoints')]
    public function test_the_money_back_promo_cannot_be_combined_with_another_code(string $endpoint): void
    {
        $holiday = $this->makeRewardCoupon();
        $regular = $this->makePromoCode('SAVE10');

        $this->actingAs($this->client, 'api')
            ->postJson($endpoint, ['coupon_ids' => [$holiday->id, $regular->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['coupon_ids' => Coupon::COMBINATION_ERROR_MESSAGE]);
    }

    #[DataProvider('productOrderEndpoints')]
    public function test_a_single_promo_code_passes_the_promo_code_rule(string $endpoint): void
    {
        $coupon = $this->makePromoCode('SAVE10');

        // The rest of the payload is left empty on purpose: other fields may
        // fail validation, but coupon_ids must never be the reason.
        $this->actingAs($this->client, 'api')
            ->postJson($endpoint, ['coupon_ids' => [$coupon->id]])
            ->assertJsonMissingValidationErrors(['coupon_ids']);
    }
}
