<?php

namespace Tests\Feature\Order;

use App\Models\ContentBriefOrder;
use App\Models\ContentBriefTier;
use App\Models\ContentOptimizationOrder;
use App\Models\ContentOptimizationTier;
use App\Models\DrTier;
use App\Models\LinkBuildingOrder;
use App\Models\NewContentOrder;
use App\Models\NewContentTier;
use App\Models\User;
use App\Services\OrderDetailsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit-level coverage of OrderDetailsService: the completeness rules, paid-status
 * resolution, the "skip for now" force-pending path, and the Link Building
 * turnaround clock (including its idempotency).
 */
class OrderDetailsServiceTest extends TestCase
{
    use RefreshDatabase;

    private OrderDetailsService $service;
    private User $user;
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(OrderDetailsService::class);
        $this->user    = User::factory()->create(['is_active' => true]);

        DrTier::create([
            'id' => 'dr30', 'label' => 'DR 30+', 'min_dr' => 30, 'max_dr' => 39,
            'traffic_range' => '5k', 'word_count' => 500, 'price_per_link' => 100.0,
            'is_hidden' => false, 'is_active' => true,
        ]);
        NewContentTier::create(['id' => 'nc', 'label' => 'Article', 'turnaround_time' => '6 Days', 'price' => 100.0, 'is_active' => true]);
        ContentOptimizationTier::create(['id' => 'co', 'label' => 'Opt', 'word_count_range' => '500', 'turnaround_days' => 5, 'price' => 100.0, 'is_active' => true]);
        ContentBriefTier::create(['id' => 'cb', 'label' => 'Brief', 'turnaround_days' => 5, 'price' => 100.0, 'is_active' => true]);
    }

    private function lbOrder(array $placement_specs): LinkBuildingOrder
    {
        $order = LinkBuildingOrder::create([
            'user_id' => $this->user->id, 'subtotal_before_discount' => 100.0,
            'total_amount' => 100.0, 'status' => 'pending_details',
        ]);
        $item = $order->items()->create(['dr_tier_id' => 'dr30', 'quantity' => count($placement_specs) ?: 1, 'unit_price' => 100.0, 'subtotal' => 100.0]);
        foreach ($placement_specs as $i => $spec) {
            $item->placements()->create([
                'order_id' => 'BL-U-' . ($this->seq++), 'row_index' => $i, 'exact_match' => false,
                'keyword' => $spec['keyword'] ?? null, 'landing_page' => $spec['landing_page'] ?? null,
                'status' => 'Pending Details', 'user_id' => $this->user->id,
            ]);
        }

        return $order->fresh();
    }

    // ── Completeness ─────────────────────────────────────────────────────────

    public function test_link_building_complete_only_when_all_placements_filled(): void
    {
        $complete = $this->lbOrder([
            ['keyword' => 'a', 'landing_page' => 'https://a.com'],
            ['keyword' => 'b', 'landing_page' => 'https://b.com'],
        ]);
        $this->assertTrue($this->service->isLinkBuildingComplete($complete));

        $partial = $this->lbOrder([
            ['keyword' => 'a', 'landing_page' => 'https://a.com'],
            ['keyword' => null, 'landing_page' => null],
        ]);
        $this->assertFalse($this->service->isLinkBuildingComplete($partial));
    }

    public function test_new_content_complete_requires_keyword_and_type(): void
    {
        $order = NewContentOrder::create(['user_id' => $this->user->id, 'subtotal_before_discount' => 100, 'total_amount' => 100, 'status' => 'pending_details']);
        $item  = $order->items()->create(['tier_id' => 'nc', 'quantity' => 1, 'unit_price' => 100, 'subtotal' => 100]);

        $this->assertFalse($this->service->isNewContentComplete($order->fresh())); // no rows

        $item->intakeRows()->create(['row_index' => 1, 'keyword_phrase' => 'kw', 'type_of_content' => null, 'status' => 'pending']);
        $this->assertFalse($this->service->isNewContentComplete($order->fresh())); // missing type

        $item->intakeRows()->delete();
        $item->intakeRows()->create(['row_index' => 1, 'keyword_phrase' => 'kw', 'type_of_content' => 'Blog Article', 'status' => 'pending']);
        $this->assertTrue($this->service->isNewContentComplete($order->fresh()));
    }

    public function test_content_optimization_and_brief_complete_require_keyword_and_url(): void
    {
        $co   = ContentOptimizationOrder::create(['user_id' => $this->user->id, 'subtotal_before_discount' => 100, 'total_amount' => 100, 'status' => 'pending_details']);
        $item = $co->items()->create(['tier_id' => 'co', 'quantity' => 1, 'unit_price' => 100, 'subtotal' => 100]);
        $item->intakeRows()->create(['row_index' => 1, 'primary_keyword' => 'kw', 'content_page_url' => null]);
        $this->assertFalse($this->service->isContentOptimizationComplete($co->fresh()));

        $item->intakeRows()->delete();
        $item->intakeRows()->create(['row_index' => 1, 'primary_keyword' => 'kw', 'content_page_url' => 'https://a.com']);
        $this->assertTrue($this->service->isContentOptimizationComplete($co->fresh()));

        $cb    = ContentBriefOrder::create(['user_id' => $this->user->id, 'subtotal_before_discount' => 100, 'total_amount' => 100, 'status' => 'pending_details']);
        $cbItem = $cb->items()->create(['tier_id' => 'cb', 'quantity' => 1, 'unit_price' => 100, 'subtotal' => 100]);
        $cbItem->intakeRows()->create(['row_index' => 1, 'primary_keyword' => 'kw', 'content_page_url' => 'https://b.com']);
        $this->assertTrue($this->service->isContentBriefComplete($cb->fresh()));
    }

    // ── Status resolution ──────────────────────────────────────────────────

    public function test_resolve_paid_status(): void
    {
        $complete = $this->lbOrder([['keyword' => 'a', 'landing_page' => 'https://a.com']]);
        $this->assertSame('new_request', $this->service->resolvePaidStatus($complete));

        $incomplete = $this->lbOrder([['keyword' => null, 'landing_page' => null]]);
        $this->assertSame('pending_details', $this->service->resolvePaidStatus($incomplete));
    }

    public function test_apply_paid_status_force_pending_parks_complete_order_without_clock(): void
    {
        $order = $this->lbOrder([['keyword' => 'a', 'landing_page' => 'https://a.com']]);

        $this->service->applyPaidStatus($order, true);

        $this->assertSame('pending_details', $order->fresh()->status);
        $placement = $order->fresh()->items->flatMap->placements->first();
        $this->assertEmpty($placement->estimated_delivery_date);
    }

    public function test_apply_paid_status_starts_clock_for_complete_link_building(): void
    {
        $order = $this->lbOrder([['keyword' => 'a', 'landing_page' => 'https://a.com']]);

        $this->service->applyPaidStatus($order);

        $this->assertSame('new_request', $order->fresh()->status);
        $placement = $order->fresh()->items->flatMap->placements->first();
        $this->assertNotEmpty($placement->estimated_delivery_date);
        $this->assertSame('30', (string) $placement->estimated_turnaround_days);
    }

    public function test_start_link_building_clock_is_idempotent(): void
    {
        $order     = $this->lbOrder([['keyword' => 'a', 'landing_page' => 'https://a.com']]);
        $placement = $order->items->flatMap->placements->first();
        $placement->update(['estimated_delivery_date' => '01/01/2099', 'estimated_turnaround_days' => '90']);

        $this->service->startLinkBuildingClock($order->fresh());

        // An already-scheduled placement keeps its existing (admin-adjusted) estimate.
        $this->assertSame('01/01/2099', $placement->fresh()->estimated_delivery_date);
        $this->assertSame('90', (string) $placement->fresh()->estimated_turnaround_days);
    }
}
