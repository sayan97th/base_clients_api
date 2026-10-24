<?php

namespace Tests\Support\LinkBuilding;

use App\Models\DrTier;
use App\Models\LinkBuildingOrder;
use App\Models\LinkBuildingOrderPlacement;
use App\Models\LinkBuildingOrderPlacementRevision;
use App\Models\Role;
use App\Models\User;

/**
 * Shared setup of the Link Building Orders edit history and Original Order tests:
 * roles, an admin, dashboard rows and client purchases to change and restore.
 */
trait InteractsWithLinkBuildingHistory
{
    protected User $admin;

    protected function setUpLinkBuildingHistory(): void
    {
        foreach (['super_admin', 'admin', 'staff', 'client'] as $role) {
            Role::firstOrCreate(['name' => $role], ['display_name' => ucfirst($role), 'description' => $role]);
        }

        $this->admin = $this->makeUser('admin', ['first_name' => 'Krista', 'last_name' => 'B']);
    }

    protected function makeUser(string $role, array $attributes = []): User
    {
        $user = User::factory()->create(['is_active' => true, ...$attributes]);
        $user->assignRole($role);

        return $user;
    }

    /** A dashboard row, by default one of the Weave orders of the reported incident. */
    protected function placement(array $overrides = []): LinkBuildingOrderPlacement
    {
        return LinkBuildingOrderPlacement::create([
            'order_id'     => 'BL-' . fake()->unique()->numberBetween(1000, 99999),
            'client'       => 'Weave',
            'keyword'      => 'other fatigue icd 10',
            'landing_page' => 'https://www.getweave.com/fatigue-icd-10/',
            'link_type'    => 'DR 60+ External',
            'exact_match'  => true,
            'status'       => 'New Request',
            ...$overrides,
        ]);
    }

    protected function drTier(string $id = 'dr60', float $price = 100.0): DrTier
    {
        return DrTier::firstOrCreate(['id' => $id], [
            'label'          => 'DR 60+',
            'min_dr'         => 60,
            'max_dr'         => 100,
            'traffic_range'  => '10k+',
            'word_count'     => 800,
            'price_per_link' => $price,
            'is_hidden'      => false,
            'is_active'      => true,
        ]);
    }

    /** A client purchase with one placement per given placement attributes. */
    protected function clientOrder(User $client, array $placements = [[]], array $order_overrides = []): LinkBuildingOrder
    {
        $this->drTier();

        $order = LinkBuildingOrder::create([
            'user_id'      => $client->id,
            'order_title'  => 'Weave links',
            'total_amount' => 100 * count($placements),
            'status'       => 'processing',
            ...$order_overrides,
        ]);

        $item = $order->items()->create([
            'dr_tier_id' => 'dr60',
            'quantity'   => count($placements),
            'unit_price' => 100,
            'subtotal'   => 100 * count($placements),
        ]);

        foreach (array_values($placements) as $index => $attributes) {
            $item->placements()->create([
                'order_id'  => 'BL-' . fake()->unique()->numberBetween(100000, 999999),
                'row_index' => $index,
                'status'    => 'New Request',
                'user_id'   => $client->id,
                ...$attributes,
            ]);
        }

        return $order->fresh();
    }

    /** Sends a paste (or another batch cell update) like the dashboard grid does. */
    protected function pasteOver(array $fields_by_row_id, ?string $source = 'paste'): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/batch-update-cells', [
                'change_source' => $source,
                'updates'       => array_map(
                    fn ($id, $fields) => ['id' => $id, 'fields' => $fields],
                    array_keys($fields_by_row_id),
                    $fields_by_row_id
                ),
            ])
            ->assertOk();
    }

    protected function lastBatchId(): string
    {
        return LinkBuildingOrderPlacementRevision::orderByDesc('id')->value('batch_id');
    }

    protected function restore(array $body, int $expected_status = 200): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/history/restore', $body)
            ->assertStatus($expected_status);
    }
}
