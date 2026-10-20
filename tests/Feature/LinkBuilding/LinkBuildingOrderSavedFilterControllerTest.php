<?php

namespace Tests\Feature\LinkBuilding;

use App\Models\LinkBuildingOrderSavedFilter;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LinkBuildingOrderSavedFilterControllerTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = '/api/admin/link-building-orders/saved-filters';

    private User $admin;
    private User $other_admin;
    private User $super_admin;
    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super_admin'], ['display_name' => 'Super Admin', 'description' => 'Super admin']);
        Role::firstOrCreate(['name' => 'admin'],       ['display_name' => 'Admin',       'description' => 'Admin user']);
        Role::firstOrCreate(['name' => 'staff'],       ['display_name' => 'Staff',       'description' => 'Staff user']);
        Role::firstOrCreate(['name' => 'client'],      ['display_name' => 'Client',      'description' => 'Client user']);

        $this->admin = User::factory()->create(['is_active' => true, 'first_name' => 'Ana', 'last_name' => 'Admin']);
        $this->admin->assignRole('admin');

        $this->other_admin = User::factory()->create(['is_active' => true, 'first_name' => 'Omar', 'last_name' => 'Other']);
        $this->other_admin->assignRole('staff');

        $this->super_admin = User::factory()->create(['is_active' => true]);
        $this->super_admin->assignRole('super_admin');

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
                    'values'    => ['mode' => 'include', 'values' => ['Jane', 'John']],
                ],
                'dr_lbs' => [
                    'condition' => ['operator' => 'greater_than', 'value' => '40', 'value_to' => ''],
                    'values'    => null,
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

    public function test_unauthenticated_index_returns_401(): void
    {
        $this->getJson(self::BASE_URL)->assertStatus(401);
    }

    public function test_client_role_cannot_access_saved_filters(): void
    {
        $this->actingAs($this->client, 'api')
            ->getJson(self::BASE_URL)
            ->assertStatus(403);
    }

    // ─── Index ────────────────────────────────────────────────────────────────

    public function test_index_returns_own_filters_and_filters_shared_by_others(): void
    {
        $own             = $this->savedFilter($this->admin, ['name' => 'My live links']);
        $shared_by_other = $this->savedFilter($this->other_admin, ['name' => 'Team view', 'is_shared' => true]);
        $private_other   = $this->savedFilter($this->other_admin, ['name' => 'Private view']);

        $response = $this->actingAs($this->admin, 'api')
            ->getJson(self::BASE_URL)
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id');

        $this->assertSame([$own->id, $shared_by_other->id], $ids->all());
        $this->assertNotContains($private_other->id, $ids);

        $response->assertJsonPath('data.0.is_owner', true)
            ->assertJsonPath('data.1.is_owner', false)
            ->assertJsonPath('data.1.can_delete', false)
            ->assertJsonPath('data.1.owner_name', 'Omar Other')
            ->assertJsonPath('data.0.filters.sheet_filters.link_builder.values.values', ['Jane', 'John']);
    }

    // ─── Store ────────────────────────────────────────────────────────────────

    public function test_store_creates_a_saved_filter_for_the_current_user(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson(self::BASE_URL, [
                'name'      => '  Live links needing review  ',
                'filters'   => $this->filterState(),
                'is_shared' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Live links needing review')
            ->assertJsonPath('data.is_shared', true)
            ->assertJsonPath('data.is_owner', true)
            ->assertJsonPath('data.filters.status', 'Live');

        $this->assertDatabaseHas('link_building_order_saved_filters', [
            'user_id'   => $this->admin->id,
            'name'      => 'Live links needing review',
            'is_shared' => true,
        ]);
    }

    public function test_store_rejects_a_duplicate_name_for_the_same_user(): void
    {
        $this->savedFilter($this->admin, ['name' => 'Weekly check']);

        $this->actingAs($this->admin, 'api')
            ->postJson(self::BASE_URL, ['name' => 'Weekly check', 'filters' => $this->filterState()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_store_allows_the_same_name_for_different_users(): void
    {
        $this->savedFilter($this->other_admin, ['name' => 'Weekly check']);

        $this->actingAs($this->admin, 'api')
            ->postJson(self::BASE_URL, ['name' => 'Weekly check', 'filters' => $this->filterState()])
            ->assertCreated();
    }

    public function test_store_rejects_unknown_filter_keys_and_invalid_operators(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson(self::BASE_URL, [
                'name'    => 'Broken',
                'filters' => array_merge($this->filterState(), ['unexpected' => 'value']),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('filters');

        $this->actingAs($this->admin, 'api')
            ->postJson(self::BASE_URL, [
                'name'    => 'Broken operator',
                'filters' => $this->filterState([
                    'sheet_filters' => [
                        'dr_lbs' => ['condition' => ['operator' => 'drop_table'], 'values' => null],
                    ],
                ]),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('filters.sheet_filters.dr_lbs.condition.operator');
    }

    public function test_store_keeps_advanced_filters_with_rules_and_groups(): void
    {
        $advanced_filters = [
            'operator' => 'or',
            'rules'    => [
                ['id' => 'rule_1', 'key' => 'status', 'operator' => 'is_any_of', 'value' => '', 'value_to' => '', 'values' => ['Live'], 'is_disabled' => false],
                // A half built rule (no column yet) is kept as part of the view.
                ['id' => 'rule_2', 'key' => null, 'operator' => null, 'value' => '', 'value_to' => '', 'values' => [], 'is_disabled' => false],
            ],
            'groups' => [
                [
                    'id'       => 'group_1',
                    'operator' => 'and',
                    'rules'    => [
                        ['id' => 'rule_3', 'key' => 'dr_lbs', 'operator' => 'is_between', 'value' => '30', 'value_to' => '60', 'values' => [], 'is_disabled' => true],
                    ],
                ],
            ],
        ];

        $this->actingAs($this->admin, 'api')
            ->postJson(self::BASE_URL, [
                'name'    => 'Live or mid DR',
                'filters' => $this->filterState(['advanced_filters' => $advanced_filters]),
            ])
            ->assertCreated()
            ->assertJsonPath('data.filters.advanced_filters.operator', 'or')
            ->assertJsonPath('data.filters.advanced_filters.rules.0.values', ['Live'])
            ->assertJsonPath('data.filters.advanced_filters.groups.0.rules.0.value_to', '60')
            ->assertJsonPath('data.filters.advanced_filters.groups.0.rules.0.is_disabled', true);
    }

    public function test_store_rejects_invalid_advanced_filter_rules(): void
    {
        $rule = ['id' => 'rule_1', 'key' => 'status', 'operator' => 'is_any_of', 'values' => ['Live']];

        $this->actingAs($this->admin, 'api')
            ->postJson(self::BASE_URL, [
                'name'    => 'Broken join',
                'filters' => $this->filterState(['advanced_filters' => ['operator' => 'xor', 'rules' => [$rule]]]),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('filters.advanced_filters.operator');

        $this->actingAs($this->admin, 'api')
            ->postJson(self::BASE_URL, [
                'name'    => 'Broken rule operator',
                'filters' => $this->filterState([
                    'advanced_filters' => [
                        'operator' => 'and',
                        'groups'   => [['operator' => 'and', 'rules' => [array_merge($rule, ['operator' => 'drop_table'])]]],
                    ],
                ]),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('filters.advanced_filters.groups.0.rules.0.operator');

        $this->actingAs($this->admin, 'api')
            ->postJson(self::BASE_URL, [
                'name'    => 'Broken column key',
                'filters' => $this->filterState([
                    'advanced_filters' => ['operator' => 'and', 'rules' => [array_merge($rule, ['key' => 'status; DROP'])]],
                ]),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('filters.advanced_filters.rules.0.key');
    }

    public function test_store_enforces_the_per_user_limit(): void
    {
        for ($i = 0; $i < LinkBuildingOrderSavedFilter::MAX_PER_USER; $i++) {
            $this->savedFilter($this->admin, ['name' => "Filter {$i}"]);
        }

        $this->actingAs($this->admin, 'api')
            ->postJson(self::BASE_URL, ['name' => 'One too many', 'filters' => $this->filterState()])
            ->assertStatus(422);
    }

    // ─── Update ───────────────────────────────────────────────────────────────

    public function test_owner_can_rename_share_and_overwrite_filters(): void
    {
        $saved_filter = $this->savedFilter($this->admin, ['name' => 'Old name']);

        $this->actingAs($this->admin, 'api')
            ->putJson(self::BASE_URL . '/' . $saved_filter->id, [
                'name'      => 'New name',
                'is_shared' => true,
                'filters'   => $this->filterState(['status' => 'Pending']),
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'New name')
            ->assertJsonPath('data.is_shared', true)
            ->assertJsonPath('data.filters.status', 'Pending');
    }

    public function test_renaming_to_its_own_current_name_is_allowed(): void
    {
        $saved_filter = $this->savedFilter($this->admin, ['name' => 'Same name']);

        $this->actingAs($this->admin, 'api')
            ->putJson(self::BASE_URL . '/' . $saved_filter->id, ['name' => 'Same name'])
            ->assertOk();
    }

    public function test_non_owner_cannot_update_a_shared_filter(): void
    {
        $saved_filter = $this->savedFilter($this->other_admin, ['is_shared' => true]);

        $this->actingAs($this->admin, 'api')
            ->putJson(self::BASE_URL . '/' . $saved_filter->id, ['name' => 'Hijacked'])
            ->assertNotFound();
    }

    // ─── Destroy ──────────────────────────────────────────────────────────────

    public function test_owner_can_delete_their_filter(): void
    {
        $saved_filter = $this->savedFilter($this->admin);

        $this->actingAs($this->admin, 'api')
            ->deleteJson(self::BASE_URL . '/' . $saved_filter->id)
            ->assertOk();

        $this->assertDatabaseMissing('link_building_order_saved_filters', ['id' => $saved_filter->id]);
    }

    public function test_non_owner_cannot_delete_a_shared_filter(): void
    {
        $saved_filter = $this->savedFilter($this->other_admin, ['is_shared' => true]);

        $this->actingAs($this->admin, 'api')
            ->deleteJson(self::BASE_URL . '/' . $saved_filter->id)
            ->assertNotFound();

        $this->assertDatabaseHas('link_building_order_saved_filters', ['id' => $saved_filter->id]);
    }

    public function test_super_admin_can_delete_a_filter_shared_by_another_user(): void
    {
        $saved_filter = $this->savedFilter($this->other_admin, ['is_shared' => true]);

        $this->actingAs($this->super_admin, 'api')
            ->deleteJson(self::BASE_URL . '/' . $saved_filter->id)
            ->assertOk();

        $this->assertDatabaseMissing('link_building_order_saved_filters', ['id' => $saved_filter->id]);
    }

    public function test_super_admin_cannot_delete_another_users_private_filter(): void
    {
        $saved_filter = $this->savedFilter($this->other_admin);

        $this->actingAs($this->super_admin, 'api')
            ->deleteJson(self::BASE_URL . '/' . $saved_filter->id)
            ->assertNotFound();
    }
}
