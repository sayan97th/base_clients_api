<?php

namespace Tests\Feature\LinkBuilding\OriginalOrder;

use App\Models\LinkBuildingOrder;
use App\Models\LinkBuildingOrderPlacementRevision;
use App\Models\LinkBuildingOrderSnapshot;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\LinkBuilding\InteractsWithLinkBuildingHistory;
use Tests\TestCase;

/**
 * Every Link Building checkout keeps a read only copy of the order exactly as the
 * client submitted it, whatever the payment method, in the same transaction as
 * the order itself.
 */
class OriginalOrderCheckoutSnapshotTest extends TestCase
{
    use RefreshDatabase, InteractsWithLinkBuildingHistory;

    private \App\Models\User $client;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->setUpLinkBuildingHistory();
        $this->drTier();
        $this->client = $this->makeUser('client', ['credit_balance' => 500, 'company' => 'Weave']);
    }

    private function mockStripe(bool $verified = true): void
    {
        $stripe = Mockery::mock(StripeService::class);
        $stripe->shouldReceive('verifyPaymentIntent')->andReturn(['verified' => $verified]);
        $stripe->shouldReceive('capturePaymentIntent')->andReturn(['success' => true]);
        $stripe->shouldReceive('cancelPaymentIntent')->andReturn(['success' => true, 'voided' => true]);
        $this->app->instance(StripeService::class, $stripe);
    }

    private function linkBuildingItem(): array
    {
        return [
            'dr_tier_id' => 'dr60',
            'quantity'   => 2,
            'unit_price' => 100.0,
            'placements' => [
                ['row_index' => 0, 'keyword' => 'other fatigue icd 10', 'landing_page' => 'https://www.getweave.com/fatigue-icd-10/', 'exact_match' => true],
                ['row_index' => 1, 'keyword' => 'dental front office training', 'landing_page' => 'https://www.getweave.com/dental-front-office-training/', 'exact_match' => false],
            ],
        ];
    }

    private function cartPayload(array $overrides = []): array
    {
        return [
            'payment_method_id'          => 'pi_test_card',
            'total_amount'               => 200.0,
            'session_id'                 => (string) Str::uuid(),
            'order_title'                => 'Weave links',
            'order_notes'                => 'Please prioritize',
            'billing'                    => [
                'company' => 'Weave', 'address' => '1 Main St', 'city' => 'Lehi',
                'state'   => 'UT', 'country' => 'US', 'postal_code' => '84043',
            ],
            'coupon_ids'                 => [],
            'link_building_items'        => [$this->linkBuildingItem()],
            'content_optimization_items' => null,
            'new_content_items'          => null,
            'content_brief_items'        => null,
            'credits_amount'             => 0,
            ...$overrides,
        ];
    }

    private function cartCheckout(array $overrides = []): LinkBuildingOrder
    {
        $order_id = $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout', $this->cartPayload($overrides))
            ->assertOk()
            ->json('data.orders.0.order_id');

        return LinkBuildingOrder::findOrFail($order_id);
    }

    public static function paymentMethodProvider(): array
    {
        return [
            'credit card'             => [[], LinkBuildingOrderSnapshot::PAYMENT_CREDIT_CARD],
            'account balance'         => [['payment_method_id' => 'credits_pay_200'], LinkBuildingOrderSnapshot::PAYMENT_ACCOUNT_BALANCE],
            'card and account balance' => [['payment_method_id' => 'pi_hybrid', 'credits_amount' => 50.0], LinkBuildingOrderSnapshot::PAYMENT_CARD_AND_BALANCE],
        ];
    }

    #[DataProvider('paymentMethodProvider')]
    public function test_a_cart_checkout_keeps_the_order_as_submitted(array $overrides, string $payment_method): void
    {
        $this->mockStripe();

        $snapshot = $this->cartCheckout($overrides)->snapshots()->sole();

        $this->assertSame(LinkBuildingOrderSnapshot::TYPE_PLACED, $snapshot->type);
        $this->assertSame($payment_method, $snapshot->payment_method);
        $this->assertFalse($snapshot->is_backfilled);
        $this->assertSame($this->client->id, $snapshot->captured_by_id);
        $this->assertSame(1, $snapshot->payload['schema_version']);
    }

    public function test_the_copy_holds_the_order_customer_billing_items_and_every_placement(): void
    {
        $this->mockStripe();

        $payload = $this->cartCheckout()->snapshots()->sole()->payload;

        $this->assertSame('Weave links', $payload['order']['order_title']);
        $this->assertSame('Please prioritize', $payload['order']['order_notes']);
        $this->assertEquals(200, $payload['order']['total_amount']);
        $this->assertSame($this->client->email, $payload['customer']['email']);
        $this->assertSame('Weave', $payload['customer']['company']);
        $this->assertSame('Lehi', $payload['billing']['city']);
        $this->assertSame('DR 60+', $payload['items'][0]['dr_tier_label']);
        $this->assertSame(2, $payload['items'][0]['quantity']);

        [$first, $second] = $payload['items'][0]['placements'];
        $this->assertSame('other fatigue icd 10', $first['keyword']);
        $this->assertSame('https://www.getweave.com/fatigue-icd-10/', $first['landing_page']);
        $this->assertTrue($first['exact_match']);
        $this->assertSame('DR 60+ External', $first['link_type']);
        $this->assertSame('Weave', $first['client']);
        $this->assertMatchesRegularExpression('/^BL-\d+$/', $first['order_id']);
        $this->assertSame('dental front office training', $second['keyword']);
        $this->assertFalse($second['exact_match']);
    }

    public function test_the_checkout_rows_are_recorded_in_the_edit_history(): void
    {
        $this->mockStripe();

        $order = $this->cartCheckout();

        // The same action also starts the turnaround clock of each row (updated events).
        $revisions = LinkBuildingOrderPlacementRevision::where('source', 'checkout')->where('event', 'created')->get();
        $this->assertCount(2, $revisions);
        $this->assertCount(1, LinkBuildingOrderPlacementRevision::where('source', 'checkout')->pluck('batch_id')->unique());
        $this->assertSame($order->items->first()->placements->pluck('id')->sort()->values()->all(), $revisions->pluck('placement_id')->sort()->values()->all());
    }

    public function test_a_pay_later_checkout_keeps_the_order_as_submitted(): void
    {
        $order_id = $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout/deferred', [
                'deferred_payment'           => true,
                'total_amount'               => 200.0,
                'session_id'                 => (string) Str::uuid(),
                'order_title'                => 'Pay Later Order',
                'order_notes'                => null,
                'coupon_ids'                 => [],
                'link_building_items'        => [$this->linkBuildingItem()],
                'content_optimization_items' => null,
                'new_content_items'          => null,
                'content_brief_items'        => null,
            ])
            ->assertOk()
            ->json('data.orders.0.order_id');

        $snapshot = LinkBuildingOrder::findOrFail($order_id)->snapshots()->sole();
        $this->assertSame(LinkBuildingOrderSnapshot::PAYMENT_PAY_LATER_INVOICE, $snapshot->payment_method);
        $this->assertSame('other fatigue icd 10', $snapshot->payload['items'][0]['placements'][0]['keyword']);
        $this->assertNull($snapshot->payload['billing']);
    }

    public function test_the_direct_card_checkout_keeps_the_order_as_submitted(): void
    {
        $this->mockStripe();

        $this->actingAs($this->client, 'api')
            ->postJson('/api/link-building/orders', [
                'order_title'  => 'Direct order',
                'total_amount' => 200.0,
                'items'        => [$this->linkBuildingItem()],
                'billing'      => ['company' => 'Weave', 'address' => '1 Main St', 'city' => 'Lehi', 'state' => 'UT', 'country' => 'US', 'postal_code' => '84043'],
                'payment'      => ['payment_method_id' => 'pi_direct'],
            ])
            ->assertSuccessful();

        $snapshot = LinkBuildingOrder::where('order_title', 'Direct order')->sole()->snapshots()->sole();
        $this->assertSame(LinkBuildingOrderSnapshot::PAYMENT_CREDIT_CARD, $snapshot->payment_method);
        $this->assertSame('dental front office training', $snapshot->payload['items'][0]['placements'][1]['keyword']);
    }

    public function test_a_failed_payment_leaves_no_copy_behind(): void
    {
        $this->mockStripe(verified: false);

        $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout', $this->cartPayload())
            ->assertClientError();

        $this->assertSame(0, LinkBuildingOrderSnapshot::count());
    }
}
