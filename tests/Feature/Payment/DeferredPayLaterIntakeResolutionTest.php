<?php

namespace Tests\Feature\Payment;

use App\Models\DrTier;
use App\Models\LinkBuildingOrderPlacement;
use App\Models\NewContentTier;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the "Skip for now" + Pay Later (Request Invoice) combination end to
 * end: checkout without charging the card creates a `payment_pending` order
 * and an unpaid invoice, and only when that invoice is later paid does the
 * order resolve to either `new_request` or `pending_details`.
 *
 * `defer_details=true` on the deferred checkout must be honored at that later
 * payment step exactly like it already is on the immediate card-payment path
 * (see DeferredIntakeCheckoutTest::test_skip_for_now_forces_pending_details_
 * even_when_data_is_complete) — the client's explicit choice to review later
 * should not depend on which payment method they eventually use.
 */
class DeferredPayLaterIntakeResolutionTest extends TestCase
{
    use RefreshDatabase;

    private User $client;
    private DrTier $dr_tier;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Bus::fake();

        Role::firstOrCreate(['name' => 'client'], [
            'display_name' => 'Client',
            'description'  => 'Regular client',
        ]);

        $this->client = User::factory()->create(['is_active' => true, 'credit_balance' => 100000]);
        $this->client->assignRole('client');

        $this->dr_tier = DrTier::create([
            'id'             => 'dr30',
            'label'          => 'DR 30+',
            'min_dr'         => 30,
            'max_dr'         => 39,
            'traffic_range'  => '5k–10k',
            'word_count'     => 500,
            'price_per_link' => 100.0,
            'is_hidden'      => false,
            'is_active'      => true,
        ]);
    }

    private function baseDeferredPayload(array $overrides = []): array
    {
        return array_merge([
            'deferred_payment'           => true,
            'total_amount'               => 100.0,
            'session_id'                 => (string) Str::uuid(),
            'order_title'                => 'Pay Later Order',
            'order_notes'                => null,
            'coupon_ids'                 => [],
            'link_building_items'        => null,
            'content_optimization_items' => null,
            'new_content_items'          => null,
            'content_brief_items'        => null,
        ], $overrides);
    }

    private function completeLinkBuildingItem(int $quantity = 1): array
    {
        return [
            'dr_tier_id' => $this->dr_tier->id,
            'quantity'   => $quantity,
            'unit_price' => 100.0,
            'placements' => array_map(fn ($i) => [
                'row_index'    => $i,
                'keyword'      => 'keyword ' . $i,
                'landing_page' => 'https://example.com/p' . $i,
                'exact_match'  => false,
            ], range(0, $quantity - 1)),
        ];
    }

    private function deferredLinkBuildingItem(int $quantity = 1): array
    {
        return [
            'dr_tier_id' => $this->dr_tier->id,
            'quantity'   => $quantity,
            'unit_price' => 100.0,
            'placements' => [
                ['row_index' => 0, 'keyword' => null, 'landing_page' => null, 'exact_match' => false],
            ],
        ];
    }

    private function checkoutDeferred(array $payload): string
    {
        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout/deferred', $payload);

        $response->assertStatus(200);

        return $response->json('data.invoice_unique_id');
    }

    private function payInvoice(string $invoice_unique_id): void
    {
        // The endpoint distinguishes authenticated vs. public share-link payment
        // purely by the presence of an Authorization header, so it must be sent
        // explicitly even though actingAs() already sets the auth guard state.
        $this->actingAs($this->client, 'api')
            ->withHeaders(['Authorization' => 'Bearer dummy-token'])
            ->postJson("/api/invoices/{$invoice_unique_id}/pay", [
                'payment_method' => 'account_balance',
            ])
            ->assertStatus(200);
    }

    // ─── The fix: defer_details survives from checkout to invoice payment ──────

    public function test_skip_for_now_via_pay_later_stays_pending_details_after_the_invoice_is_paid(): void
    {
        // Client filled in every field but still clicked "Skip for now", then
        // chose "Request Invoice" instead of paying by card immediately.
        $invoice_unique_id = $this->checkoutDeferred($this->baseDeferredPayload([
            'defer_details'       => true,
            'link_building_items' => [$this->completeLinkBuildingItem(1)],
        ]));

        $this->assertDatabaseHas('invoices', [
            'unique_id'        => $invoice_unique_id,
            'details_deferred' => true,
        ]);

        $this->payInvoice($invoice_unique_id);

        $order = \App\Models\LinkBuildingOrder::where('session_title', 'Pay Later Order')->firstOrFail();

        // Even though the data was complete, the explicit "Skip for now" choice
        // must still be honored once the invoice is finally paid.
        $this->assertEquals('pending_details', $order->status);

        $placement = LinkBuildingOrderPlacement::whereHas(
            'orderItem.order',
            fn ($q) => $q->where('id', $order->id)
        )->first();

        $this->assertEquals('keyword 0', $placement->keyword);
        $this->assertEmpty($placement->estimated_delivery_date);
    }

    public function test_normal_pay_later_checkout_with_complete_data_starts_the_clock_once_paid(): void
    {
        // No "Skip for now" — data is complete and defer_details was never set.
        $invoice_unique_id = $this->checkoutDeferred($this->baseDeferredPayload([
            'link_building_items' => [$this->completeLinkBuildingItem(1)],
        ]));

        $this->assertDatabaseHas('invoices', [
            'unique_id'        => $invoice_unique_id,
            'details_deferred' => false,
        ]);

        $this->payInvoice($invoice_unique_id);

        $order = \App\Models\LinkBuildingOrder::where('session_title', 'Pay Later Order')->firstOrFail();

        $this->assertEquals('new_request', $order->status);

        $placement = LinkBuildingOrderPlacement::whereHas(
            'orderItem.order',
            fn ($q) => $q->where('id', $order->id)
        )->first();

        $this->assertNotEmpty($placement->estimated_delivery_date);
    }

    public function test_pay_later_checkout_with_incomplete_data_still_lands_in_pending_details_once_paid(): void
    {
        // The common real-world "Skip for now" case: no keyword data at all.
        $invoice_unique_id = $this->checkoutDeferred($this->baseDeferredPayload([
            'link_building_items' => [$this->deferredLinkBuildingItem(2)],
        ]));

        $this->payInvoice($invoice_unique_id);

        $order = \App\Models\LinkBuildingOrder::where('session_title', 'Pay Later Order')->firstOrFail();

        $this->assertEquals('pending_details', $order->status);
    }

    public function test_pay_later_new_content_with_defer_details_stays_pending_details_when_paid(): void
    {
        $tier = NewContentTier::create([
            'id'              => 'nc-basic',
            'label'           => 'Basic Article',
            'turnaround_time' => '6 Business Days',
            'price'           => 100.0,
            'is_active'       => true,
        ]);

        $invoice_unique_id = $this->checkoutDeferred($this->baseDeferredPayload([
            'defer_details'     => true,
            'new_content_items' => [[
                'tier_id'     => $tier->id,
                'quantity'    => 1,
                'unit_price'  => 100.0,
                'intake_rows' => [[
                    'keyword_phrase'      => 'seo tips',
                    'secondary_keywords'  => null,
                    'type_of_content'     => 'Blog Article',
                    'notes'               => null,
                ]],
            ]],
        ]));

        $this->payInvoice($invoice_unique_id);

        $this->assertDatabaseHas('new_content_orders', [
            'session_title' => 'Pay Later Order',
            'status'        => 'pending_details',
        ]);
    }
}
