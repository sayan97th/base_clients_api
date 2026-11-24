<?php

namespace Tests\Feature\Discount\PromoCodeStacking;

use App\Models\Discount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Admin\InteractsWithAdminRoles;
use Tests\TestCase;

/**
 * The "allow_promo_code_stacking" option of the admin discount form.
 * It is off for every discount unless an admin turns it on.
 */
class AdminDiscountPromoCodeStackingTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithAdminRoles;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAdminRoles();
        $this->admin = $this->makeUserWithRole('admin');
    }

    private function discountPayload(array $overrides = []): array
    {
        return array_merge([
            'name'          => 'Bulk Link Building Discount',
            'description'   => '10% off when ordering 10 links or more.',
            'discount_type' => 'bulk',
            'discount_rate' => 10,
            'min_quantity'  => 10,
            'applies_to'    => 'link_building',
            'is_active'     => true,
        ], $overrides);
    }

    public function test_new_discounts_do_not_allow_promo_code_stacking_by_default(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/discounts', $this->discountPayload());

        $response->assertCreated()->assertJsonPath('data.allow_promo_code_stacking', false);
        $this->assertDatabaseHas('discounts', [
            'id'                        => $response->json('data.id'),
            'allow_promo_code_stacking' => false,
        ]);
    }

    public function test_admin_can_create_a_discount_that_allows_promo_code_stacking(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/discounts', $this->discountPayload(['allow_promo_code_stacking' => true]));

        $response->assertCreated()->assertJsonPath('data.allow_promo_code_stacking', true);
    }

    public function test_admin_can_toggle_promo_code_stacking_on_an_existing_discount(): void
    {
        $discount = Discount::create($this->discountPayload());

        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/admin/discounts/{$discount->id}", ['allow_promo_code_stacking' => true])
            ->assertOk()
            ->assertJsonPath('data.allow_promo_code_stacking', true);

        $this->assertTrue($discount->fresh()->allow_promo_code_stacking);
    }

    public function test_updating_other_fields_keeps_the_stacking_option(): void
    {
        $discount = Discount::create($this->discountPayload(['allow_promo_code_stacking' => true]));

        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/admin/discounts/{$discount->id}", ['name' => 'Renamed discount'])
            ->assertOk()
            ->assertJsonPath('data.allow_promo_code_stacking', true);
    }

    public function test_stacking_option_must_be_a_boolean(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/discounts', $this->discountPayload(['allow_promo_code_stacking' => 'sometimes']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('allow_promo_code_stacking');
    }

    public function test_active_discounts_endpoint_exposes_the_stacking_option_to_the_checkout(): void
    {
        Discount::create($this->discountPayload());
        $client = $this->makeUserWithRole('client');

        $this->actingAs($client, 'api')
            ->getJson('/api/discounts/active')
            ->assertOk()
            ->assertJsonPath('data.0.allow_promo_code_stacking', false);
    }
}
