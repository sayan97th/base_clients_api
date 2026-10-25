<?php

namespace Tests\Feature\LinkBuilding;

use App\Models\LinkBuildingOrderPlacement;
use App\Models\LinkBuildingOrderPlacementRevision;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * API contract behind the calendar popover of the date cells in the admin "Link
 * Building Orders" table. The picker sends the picked day as MM/DD/YYYY through the
 * single row update (PUT), and an empty string when the date is cleared:
 *  - a picked day is stored and returned exactly as sent;
 *  - Clear empties the cell;
 *  - picking a Request Date keeps the Delivery Date in step with the turnaround,
 *    while picking a Delivery Date overrides it;
 *  - days_left follows the picked Delivery Date (the table marks past dates as overdue);
 *  - every pick is kept in the edit history.
 */
class LinkBuildingOrderDatePickerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin', 'description' => 'Admin user']);
        Role::firstOrCreate(['name' => 'client'], ['display_name' => 'Client', 'description' => 'Client user']);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        Carbon::setTestNow(Carbon::create(2026, 10, 25, 9));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function placement(array $overrides = []): LinkBuildingOrderPlacement
    {
        return LinkBuildingOrderPlacement::create(array_merge([
            'order_id'     => 'BL-' . rand(1000, 9999),
            'client'       => 'Acme',
            'keyword'      => 'keyword',
            'landing_page' => 'https://example.com',
            'link_type'    => 'DR 30+ External',
            'status'       => 'New Request',
        ], $overrides));
    }

    private function pickDate(LinkBuildingOrderPlacement $placement, array $fields)
    {
        return $this->actingAs($this->admin, 'api')
            ->putJson("/api/admin/link-building-orders/{$placement->id}", $fields);
    }

    // ─── Picking a day ────────────────────────────────────────────────────────

    public static function pickedDays(): array
    {
        return [
            'Live Link Date, a day of this month' => ['live_link_date', '10/30/2026'],
            'Live Link Date, Today button'        => ['live_link_date', '10/25/2026'],
            'Approval Date, +30 days quick pick'  => ['approval_date', '11/24/2026'],
            'Approval Date, across the year end'  => ['approval_date', '01/01/2027'],
            'Live Link Date, a leap day'          => ['live_link_date', '02/29/2028'],
        ];
    }

    #[DataProvider('pickedDays')]
    public function test_a_picked_day_is_saved_and_returned_as_sent(string $column, string $picked_day): void
    {
        $placement = $this->placement([$column => '10/01/2026']);

        $this->pickDate($placement, [$column => $picked_day])
            ->assertOk()
            ->assertJsonPath("data.{$column}", $picked_day);

        $this->assertSame($picked_day, $placement->fresh()->{$column});
    }

    public function test_a_day_picked_in_an_empty_cell_is_saved(): void
    {
        $placement = $this->placement();

        $this->pickDate($placement, ['live_link_date' => '10/26/2026'])
            ->assertOk()
            ->assertJsonPath('data.live_link_date', '10/26/2026');
    }

    public function test_picking_a_date_leaves_the_other_cells_of_the_row_alone(): void
    {
        $placement = $this->placement(['keyword' => 'seo tools', 'approval_date' => '10/02/2026']);

        $this->pickDate($placement, ['live_link_date' => '10/30/2026'])->assertOk();

        $fresh = $placement->fresh();
        $this->assertSame('seo tools', $fresh->keyword);
        $this->assertSame('10/02/2026', $fresh->approval_date);
    }

    // ─── Clear ────────────────────────────────────────────────────────────────

    #[DataProvider('clearableDateColumns')]
    public function test_clear_empties_the_date(string $column): void
    {
        $placement = $this->placement([$column => '10/05/2026']);

        $this->pickDate($placement, [$column => ''])
            ->assertOk()
            ->assertJsonPath("data.{$column}", '');

        $this->assertSame('', (string) $placement->fresh()->{$column});
    }

    public static function clearableDateColumns(): array
    {
        return [
            'Live Link Date' => ['live_link_date'],
            'Approval Date'  => ['approval_date'],
        ];
    }

    // ─── Request Date and Delivery Date ───────────────────────────────────────

    public function test_picking_a_request_date_moves_the_delivery_date_by_the_turnaround(): void
    {
        $placement = $this->placement([
            'request_date'              => '10/01/2026',
            'estimated_turnaround_days' => '30',
            'estimated_delivery_date'   => '10/31/2026',
        ]);

        $this->pickDate($placement, ['request_date' => '10/25/2026'])
            ->assertOk()
            ->assertJsonPath('data.request_date', '10/25/2026')
            ->assertJsonPath('data.estimated_delivery_date', '11/24/2026');
    }

    public function test_a_picked_delivery_date_overrides_the_computed_one(): void
    {
        $placement = $this->placement([
            'request_date'              => '10/01/2026',
            'estimated_turnaround_days' => '30',
            'estimated_delivery_date'   => '10/31/2026',
        ]);

        $this->pickDate($placement, ['request_date' => '10/25/2026', 'estimated_delivery_date' => '12/15/2026'])
            ->assertOk()
            ->assertJsonPath('data.estimated_delivery_date', '12/15/2026');
    }

    public function test_clearing_the_request_date_keeps_the_delivery_date(): void
    {
        $placement = $this->placement([
            'request_date'              => '10/01/2026',
            'estimated_turnaround_days' => '30',
            'estimated_delivery_date'   => '10/31/2026',
        ]);

        $this->pickDate($placement, ['request_date' => ''])->assertOk();

        $this->assertSame('10/31/2026', $placement->fresh()->estimated_delivery_date);
    }

    public static function deliveryDaysLeft(): array
    {
        return [
            'Today button'       => ['10/25/2026', 0],
            '+7 days quick pick' => ['11/01/2026', 7],
            'a past day'         => ['10/20/2026', -5],
        ];
    }

    #[DataProvider('deliveryDaysLeft')]
    public function test_days_left_follows_the_picked_delivery_date(string $picked_day, int $days_left): void
    {
        $placement = $this->placement(['estimated_delivery_date' => '12/31/2026']);

        $response = $this->pickDate($placement, ['estimated_delivery_date' => $picked_day])->assertOk();

        $this->assertSame($picked_day, $response->json('data.estimated_delivery_date'));
        $this->assertSame($days_left, (int) $response->json('data.days_left'));
    }

    // ─── History ──────────────────────────────────────────────────────────────

    public function test_a_picked_date_is_recorded_in_the_edit_history(): void
    {
        $placement = $this->placement(['live_link_date' => '10/05/2026']);

        $this->pickDate($placement, ['live_link_date' => '10/12/2026'])->assertOk();

        $revision = LinkBuildingOrderPlacementRevision::where('placement_id', $placement->id)
            ->where('event', 'updated')
            ->sole();
        $this->assertSame($this->admin->id, $revision->user_id);
        $this->assertSame(['old' => '10/05/2026', 'new' => '10/12/2026'], $revision->cell_changes['live_link_date']);
    }

    // ─── Validation and access ────────────────────────────────────────────────

    public function test_a_date_longer_than_the_column_allows_is_rejected(): void
    {
        $placement = $this->placement(['live_link_date' => '10/05/2026']);

        $this->pickDate($placement, ['live_link_date' => str_repeat('1', 21)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('live_link_date');

        $this->assertSame('10/05/2026', $placement->fresh()->live_link_date);
    }

    public function test_a_client_cannot_pick_a_date(): void
    {
        $client = User::factory()->create(['is_active' => true]);
        $client->assignRole('client');
        $placement = $this->placement(['live_link_date' => '10/05/2026']);

        $this->actingAs($client, 'api')
            ->putJson("/api/admin/link-building-orders/{$placement->id}", ['live_link_date' => '10/12/2026'])
            ->assertStatus(403);

        $this->assertSame('10/05/2026', $placement->fresh()->live_link_date);
    }

    public function test_a_guest_cannot_pick_a_date(): void
    {
        $placement = $this->placement(['live_link_date' => '10/05/2026']);

        $this->putJson("/api/admin/link-building-orders/{$placement->id}", ['live_link_date' => '10/12/2026'])
            ->assertStatus(401);
    }
}
