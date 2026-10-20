<?php

namespace Tests\Feature\LinkBuilding;

use App\Models\LinkBuildingOrderFilterPreference;
use App\Models\LinkBuildingOrderSavedFilter;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LinkBuildingOrderFilterPreferenceControllerTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = '/api/admin/link-building-orders/filter-preference';

    private User $admin;
    private User $other_admin;
    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin'],  ['display_name' => 'Admin',  'description' => 'Admin user']);
        Role::firstOrCreate(['name' => 'staff'],  ['display_name' => 'Staff',  'description' => 'Staff user']);
        Role::firstOrCreate(['name' => 'client'], ['display_name' => 'Client', 'description' => 'Client user']);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->other_admin = User::factory()->create(['is_active' => true]);
        $this->other_admin->assignRole('staff');

        $this->client = User::factory()->create(['is_active' => true]);
        $this->client->assignRole('client');
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function filterState(array $overrides = []): array
    {
        return array_merge([
            'search'               => 'acme',
            'status'               => 'Live',
            'link_type'            => '',
            'client'               => '',
            'client_user_id'       => null,
            'assigned_user_id'     => null,
            'needs_lb_tl_approval' => false,
            'sort_rules'           => [['key' => 'request_date', 'direction' => 'desc', 'nulls_last' => true]],
            'sheet_filters'        => [
                'link_builder' => [
                    'condition' => null,
                    'values'    => ['mode' => 'include', 'values' => ['Jane']],
                ],
            ],
            'hidden_columns' => ['internal_notes'],
        ], $overrides);
    }

    private function savedFilter(User $owner, array $overrides = []): LinkBuildingOrderSavedFilter
    {
        return LinkBuildingOrderSavedFilter::create(array_merge([
            'user_id'   => $owner->id,
            'name'      => 'Filter ' . uniqid(),
            'filters'   => $this->filterState(),
            'is_shared' => false,
        ], $overrides));
    }

    // ─── Authorization ────────────────────────────────────────────────────────

    public function test_unauthenticated_show_returns_401(): void
    {
        $this->getJson(self::BASE_URL)->assertStatus(401);
    }

    public function test_client_role_cannot_access_filter_preference(): void
    {
        $this->actingAs($this->client, 'api')
            ->putJson(self::BASE_URL, ['filters' => $this->filterState()])
            ->assertStatus(403);
    }

    // ─── Show ─────────────────────────────────────────────────────────────────

    public function test_show_returns_null_filters_when_nothing_was_saved(): void
    {
        $this->actingAs($this->admin, 'api')
            ->getJson(self::BASE_URL)
            ->assertOk()
            ->assertJsonPath('data.filters', null)
            ->assertJsonPath('data.applied_saved_filter_id', null);
    }

    public function test_show_only_returns_the_current_users_view(): void
    {
        LinkBuildingOrderFilterPreference::create([
            'user_id' => $this->other_admin->id,
            'filters' => $this->filterState(['search' => 'someone else']),
        ]);

        $this->actingAs($this->admin, 'api')
            ->getJson(self::BASE_URL)
            ->assertOk()
            ->assertJsonPath('data.filters', null);
    }

    public function test_show_hides_an_applied_filter_its_owner_stopped_sharing(): void
    {
        $shared = $this->savedFilter($this->other_admin, ['is_shared' => true]);

        LinkBuildingOrderFilterPreference::create([
            'user_id'                 => $this->admin->id,
            'filters'                 => $this->filterState(),
            'applied_saved_filter_id' => $shared->id,
        ]);

        $shared->update(['is_shared' => false]);

        $this->actingAs($this->admin, 'api')
            ->getJson(self::BASE_URL)
            ->assertOk()
            ->assertJsonPath('data.applied_saved_filter_id', null)
            ->assertJsonPath('data.filters.search', 'acme');
    }

    // ─── Update ───────────────────────────────────────────────────────────────

    public function test_update_creates_then_overwrites_the_users_single_view(): void
    {
        $this->actingAs($this->admin, 'api')
            ->putJson(self::BASE_URL, ['filters' => $this->filterState()])
            ->assertOk()
            ->assertJsonPath('data.filters.status', 'Live');

        $this->actingAs($this->admin, 'api')
            ->putJson(self::BASE_URL, ['filters' => $this->filterState(['status' => 'Pending'])])
            ->assertOk()
            ->assertJsonPath('data.filters.status', 'Pending');

        $this->assertSame(1, LinkBuildingOrderFilterPreference::where('user_id', $this->admin->id)->count());

        $this->actingAs($this->admin, 'api')
            ->getJson(self::BASE_URL)
            ->assertJsonPath('data.filters.status', 'Pending')
            ->assertJsonPath('data.filters.sheet_filters.link_builder.values.values', ['Jane']);
    }

    public function test_update_remembers_an_own_or_shared_applied_filter(): void
    {
        $shared = $this->savedFilter($this->other_admin, ['is_shared' => true]);

        $this->actingAs($this->admin, 'api')
            ->putJson(self::BASE_URL, [
                'filters'                 => $this->filterState(),
                'applied_saved_filter_id' => $shared->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.applied_saved_filter_id', $shared->id);
    }

    public function test_update_rejects_a_private_filter_owned_by_someone_else(): void
    {
        $private = $this->savedFilter($this->other_admin);

        $this->actingAs($this->admin, 'api')
            ->putJson(self::BASE_URL, [
                'filters'                 => $this->filterState(),
                'applied_saved_filter_id' => $private->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('applied_saved_filter_id');
    }

    public function test_update_rejects_unknown_filter_keys(): void
    {
        $this->actingAs($this->admin, 'api')
            ->putJson(self::BASE_URL, ['filters' => $this->filterState(['unexpected' => 'value'])])
            ->assertStatus(422)
            ->assertJsonValidationErrors('filters');
    }

    public function test_update_requires_filters(): void
    {
        $this->actingAs($this->admin, 'api')
            ->putJson(self::BASE_URL, [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('filters');
    }

    public function test_deleting_the_applied_saved_filter_clears_it_from_the_view(): void
    {
        $own = $this->savedFilter($this->admin);

        LinkBuildingOrderFilterPreference::create([
            'user_id'                 => $this->admin->id,
            'filters'                 => $this->filterState(),
            'applied_saved_filter_id' => $own->id,
        ]);

        $own->delete();

        $this->actingAs($this->admin, 'api')
            ->getJson(self::BASE_URL)
            ->assertOk()
            ->assertJsonPath('data.applied_saved_filter_id', null)
            ->assertJsonPath('data.filters.search', 'acme');
    }
}
