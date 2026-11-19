<?php

namespace Tests\Feature\Coupon;

use App\Jobs\SendAdminGiftCardRewardNotificationJob;
use App\Mail\AdminGiftCardRewardNotification;
use App\Models\Coupon;
use App\Models\DrTier;
use App\Models\GiftCardRewardClaim;
use App\Models\Role;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * Money back promo codes (HAPPYHOLIDAYS): the client pays full price and
 * earns a gift card worth a percentage of the order total.
 */
class GiftCardRewardCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private User $client;
    private DrTier $dr_tier;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Queue::fake();

        foreach (['client' => 'Client', 'admin' => 'Admin'] as $name => $display_name) {
            Role::firstOrCreate(['name' => $name], [
                'display_name' => $display_name,
                'description'  => $display_name,
            ]);
        }

        $this->client = User::factory()->create(['is_active' => true, 'credit_balance' => 0]);
        $this->client->assignRole('client');

        $this->dr_tier = DrTier::create([
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

        $mock = Mockery::mock(StripeService::class);
        $mock->shouldReceive('verifyPaymentIntent')->andReturn(['verified' => true]);
        $mock->shouldReceive('capturePaymentIntent')->andReturn(['success' => true]);
        $mock->shouldReceive('cancelPaymentIntent')->andReturn(['success' => true, 'voided' => true]);
        $this->app->instance(StripeService::class, $mock);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function makeRewardCoupon(array $overrides = []): Coupon
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

    private function checkout(array $coupon_ids, int $quantity = 1, ?array $gift_card_reward = null): TestResponse
    {
        return $this->actingAs($this->client, 'api')->postJson('/api/cart/checkout', array_filter([
            'payment_method_id' => 'pi_test_card',
            'total_amount'      => 400.0 * $quantity,
            'session_id'        => (string) Str::uuid(),
            'order_title'       => 'Holiday Order',
            'billing'           => [
                'company'     => 'Test Co',
                'address'     => '123 Main St',
                'city'        => 'Boise',
                'state'       => 'ID',
                'country'     => 'US',
                'postal_code' => '83701',
            ],
            'coupon_ids'          => $coupon_ids,
            'gift_card_reward'    => $gift_card_reward,
            'link_building_items' => [[
                'dr_tier_id' => $this->dr_tier->id,
                'quantity'   => $quantity,
                'unit_price' => 400.0,
                'placements' => [[
                    'row_index'    => 0,
                    'keyword'      => 'holiday keyword',
                    'landing_page' => 'https://example.com/page',
                    'exact_match'  => false,
                ]],
            ]],
        ], fn ($value) => $value !== null));
    }

    // ─── Checkout ─────────────────────────────────────────────────────────────

    public function test_reward_coupon_does_not_discount_and_records_a_submitted_claim(): void
    {
        $coupon = $this->makeRewardCoupon();

        $response = $this->checkout([$coupon->id], 2, [
            'recipient_email' => 'Gifts@Example.com',
            'gift_card_brand' => 'amazon',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.gift_card_reward.status', GiftCardRewardClaim::STATUS_SUBMITTED)
            ->assertJsonPath('data.gift_card_reward.reward_amount', 80);

        $this->assertEquals(800.0, (float) $response->json('data.orders.0.total_amount'));
        $this->assertDatabaseCount('link_building_order_coupons', 0);
        $this->assertDatabaseHas('gift_card_reward_claims', [
            'coupon_id'         => $coupon->id,
            'user_id'           => $this->client->id,
            'recipient_email'   => 'gifts@example.com',
            'gift_card_brand'   => 'amazon',
            'submission_source' => GiftCardRewardClaim::SOURCE_CHECKOUT,
        ]);
        $this->assertEquals(1, $coupon->fresh()->times_used);
        Queue::assertPushed(SendAdminGiftCardRewardNotificationJob::class);
    }

    public function test_reward_is_based_on_the_total_after_bulk_discount(): void
    {
        $coupon = $this->makeRewardCoupon();

        // 10 links trigger the 10% bulk discount, which still applies with a reward coupon.
        $response = $this->actingAs($this->client, 'api')->postJson('/api/cart/checkout', [
            'payment_method_id'   => 'pi_test_card',
            'total_amount'        => 3600.0,
            'session_id'          => (string) Str::uuid(),
            'billing'             => ['company' => null, 'address' => '', 'city' => '', 'state' => '', 'country' => '', 'postal_code' => ''],
            'coupon_ids'          => [$coupon->id],
            'link_building_items' => [[
                'dr_tier_id' => $this->dr_tier->id,
                'quantity'   => 10,
                'unit_price' => 400.0,
                'placements' => [['row_index' => 0, 'keyword' => null, 'landing_page' => null, 'exact_match' => false]],
            ]],
        ]);

        $response->assertOk()->assertJsonPath('data.gift_card_reward.reward_amount', 360);
        $this->assertEquals(3600.0, (float) $response->json('data.orders.0.total_amount'));
    }

    public function test_skipping_the_form_creates_a_pending_claim_without_notifying(): void
    {
        $coupon = $this->makeRewardCoupon();

        $this->checkout([$coupon->id])
            ->assertOk()
            ->assertJsonPath('data.gift_card_reward.status', GiftCardRewardClaim::STATUS_PENDING_DETAILS)
            ->assertJsonPath('data.gift_card_reward.is_claimable', true);

        Queue::assertNotPushed(SendAdminGiftCardRewardNotificationJob::class);
    }

    public function test_reward_coupon_cannot_be_combined_with_other_coupons(): void
    {
        $reward = $this->makeRewardCoupon();
        $other  = Coupon::create([
            'code'           => 'SAVE10',
            'name'           => 'Save 10',
            'discount_type'  => 'percentage',
            'discount_value' => 10.0,
            'applies_to'     => 'all',
            'is_active'      => true,
            'times_used'     => 0,
        ]);

        $this->checkout([$reward->id, $other->id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The HAPPYHOLIDAYS promo code cannot be combined with other promo codes.');

        $this->assertDatabaseCount('gift_card_reward_claims', 0);
    }

    public function test_regular_coupons_never_create_a_claim(): void
    {
        $coupon = Coupon::create([
            'code'           => 'SAVE10',
            'name'           => 'Save 10',
            'discount_type'  => 'percentage',
            'discount_value' => 10.0,
            'applies_to'     => 'all',
            'is_active'      => true,
            'times_used'     => 0,
        ]);

        $this->checkout([$coupon->id], 1, ['recipient_email' => 'a@example.com', 'gift_card_brand' => 'visa'])
            ->assertOk()
            ->assertJsonPath('data.gift_card_reward', null);

        $this->assertDatabaseCount('gift_card_reward_claims', 0);
    }

    public function test_validate_endpoint_reports_the_reward_without_a_discount(): void
    {
        $this->makeRewardCoupon();

        $this->actingAs($this->client, 'api')->postJson('/api/coupons/validate', [
            'code'         => 'happyholidays',
            'order_amount' => 500,
        ])
            ->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('is_gift_card_reward', true)
            ->assertJsonPath('discount_amount', 0)
            ->assertJsonPath('reward_amount', 50)
            ->assertJsonCount(3, 'gift_card_brands');
    }

    // ─── Rescue form on the order page ────────────────────────────────────────

    public function test_client_can_submit_details_later_from_the_order_page(): void
    {
        $coupon   = $this->makeRewardCoupon();
        $response = $this->checkout([$coupon->id]);
        $order_id = $response->json('data.orders.0.order_id');
        $claim_id = $response->json('data.gift_card_reward.id');

        $this->actingAs($this->client, 'api')
            ->getJson('/api/gift-card-rewards/lookup?order_id=' . $order_id)
            ->assertOk()
            ->assertJsonPath('data.id', $claim_id)
            ->assertJsonPath('data.is_claimable', true);

        $this->actingAs($this->client, 'api')
            ->putJson('/api/gift-card-rewards/' . $claim_id, [
                'recipient_email' => 'me@example.com',
                'gift_card_brand' => 'target',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', GiftCardRewardClaim::STATUS_SUBMITTED)
            ->assertJsonPath('data.is_claimable', false);

        Queue::assertPushed(SendAdminGiftCardRewardNotificationJob::class);

        $this->actingAs($this->client, 'api')
            ->putJson('/api/gift-card-rewards/' . $claim_id, [
                'recipient_email' => 'other@example.com',
                'gift_card_brand' => 'visa',
            ])
            ->assertStatus(409);
    }

    public function test_form_disappears_once_the_promotion_has_ended(): void
    {
        $coupon   = $this->makeRewardCoupon();
        $response = $this->checkout([$coupon->id]);
        $claim_id = $response->json('data.gift_card_reward.id');

        $coupon->update(['is_active' => false]);

        $this->actingAs($this->client, 'api')
            ->getJson('/api/gift-card-rewards/lookup?session_id=' . $response->json('data.session_id'))
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->actingAs($this->client, 'api')
            ->putJson('/api/gift-card-rewards/' . $claim_id, [
                'recipient_email' => 'me@example.com',
                'gift_card_brand' => 'amazon',
            ])
            ->assertStatus(422);
    }

    public function test_other_clients_cannot_submit_someone_elses_reward(): void
    {
        $coupon   = $this->makeRewardCoupon();
        $claim_id = $this->checkout([$coupon->id])->json('data.gift_card_reward.id');

        $intruder = User::factory()->create(['is_active' => true]);
        $intruder->assignRole('client');

        $this->actingAs($intruder, 'api')
            ->putJson('/api/gift-card-rewards/' . $claim_id, [
                'recipient_email' => 'intruder@example.com',
                'gift_card_brand' => 'visa',
            ])
            ->assertNotFound();
    }

    public function test_invalid_brand_is_rejected(): void
    {
        $coupon   = $this->makeRewardCoupon();
        $claim_id = $this->checkout([$coupon->id])->json('data.gift_card_reward.id');

        $this->actingAs($this->client, 'api')
            ->putJson('/api/gift-card-rewards/' . $claim_id, [
                'recipient_email' => 'me@example.com',
                'gift_card_brand' => 'walmart',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('gift_card_brand');
    }

    // ─── Admin ────────────────────────────────────────────────────────────────

    public function test_admin_can_list_claims_and_mark_them_as_sent(): void
    {
        $coupon   = $this->makeRewardCoupon();
        $claim_id = $this->checkout([$coupon->id], 1, [
            'recipient_email' => 'gifts@example.com',
            'gift_card_brand' => 'visa',
        ])->json('data.gift_card_reward.id');

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $this->actingAs($admin, 'api')
            ->getJson('/api/admin/gift-card-claims?coupon_id=' . $coupon->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.gift_card_brand_label', 'Visa')
            ->assertJsonPath('data.0.client.email', $this->client->email);

        $this->actingAs($admin, 'api')
            ->patchJson('/api/admin/gift-card-claims/' . $claim_id . '/fulfillment', ['is_fulfilled' => true])
            ->assertOk()
            ->assertJsonPath('data.status', GiftCardRewardClaim::STATUS_FULFILLED);
    }

    public function test_admin_notification_email_renders(): void
    {
        $mailable = new AdminGiftCardRewardNotification(
            recipient_name: 'Abby',
            recipient_email: 'abby@example.com',
            client_name: 'Jane Client',
            client_email: 'jane@example.com',
            client_company: 'Acme',
            client_initials: 'JC',
            promo_code: 'HAPPYHOLIDAYS',
            promotion_name: 'Happy Holidays 10% Back',
            gift_card_brand: 'amazon',
            gift_card_label: 'Amazon',
            gift_card_email: 'gifts@example.com',
            reward_amount: '$80.00',
            reward_percentage: '10%',
            order_total: '$800.00',
            order_reference: 'ABCD1234',
            products: 'Link Building',
            is_payment_pending: true,
            submitted_at: 'November 18, 2026 at 3:00 PM',
            view_order_url: 'https://portal.test/admin/orders/1',
            view_claims_url: 'https://portal.test/admin/coupons/1/edit#gift-card-claims',
            settings_url: 'https://portal.test/admin/email-notifications',
        );

        $html = $mailable->render();

        $this->assertStringContainsString('gifts@example.com', $html);
        $this->assertStringContainsString('$80.00', $html);
        $this->assertStringContainsString('Amazon', $html);
        $this->assertStringContainsString('pay later', $html);
    }
}
