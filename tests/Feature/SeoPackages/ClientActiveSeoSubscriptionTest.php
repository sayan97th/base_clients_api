<?php

namespace Tests\Feature\SeoPackages;

use App\Models\SeoPackage;
use App\Models\SeoPackageSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\Admin\InteractsWithAdminRoles;
use Tests\Support\SeoSubscriptions\InteractsWithSeoSubscriptions;
use Tests\TestCase;

/**
 * Client side "active subscription" banner data: a custom priced plan shows
 * the client's own price and plan name, even without a listed package.
 */
class ClientActiveSeoSubscriptionTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithAdminRoles;
    use InteractsWithSeoSubscriptions;

    private const URL = '/api/seo-packages/subscriptions/active';

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-22 15:00:00');
        $this->seedAdminRoles();
        $this->client = $this->makeUserWithRole('client');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_custom_plan_without_a_package_returns_the_negotiated_price(): void
    {
        $this->makeSubscriptionFor($this->client, ['plan_name' => 'Custom Growth Plus', 'monthly_price' => 2000]);

        $this->actingAs($this->client, 'api')
            ->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.plan_name', 'Custom Growth Plus')
            ->assertJsonPath('data.pricing_type', 'custom')
            ->assertJsonPath('data.monthly_price', 2000)
            ->assertJsonPath('data.auto_renew', true)
            ->assertJsonPath('data.next_billing_date', '2026-12-01')
            ->assertJsonPath('data.package', null);
    }

    public function test_package_plan_includes_the_listed_package(): void
    {
        $this->makePackage();
        $this->makeSubscriptionFor($this->client, [
            'seo_package_id' => 'growth',
            'plan_name'      => 'Growth',
            'pricing_type'   => 'package',
            'monthly_price'  => 1499,
        ]);

        $this->actingAs($this->client, 'api')
            ->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.package.name', 'Growth')
            ->assertJsonPath('data.monthly_price', 1499);
    }

    public function test_cancelled_and_other_clients_subscriptions_are_not_returned(): void
    {
        $this->makeSubscriptionFor($this->client, ['status' => 'cancelled']);
        $this->makeSubscriptionFor($this->makeUserWithRole('client'));

        $this->actingAs($this->client, 'api')
            ->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_the_client_never_sees_admin_only_billing_fields(): void
    {
        $this->makeSubscriptionFor($this->client, ['notes' => 'Internal: agreed discount']);

        $response = $this->actingAs($this->client, 'api')->getJson(self::URL)->assertOk();

        $this->assertArrayNotHasKey('notes', $response->json('data'));
        $this->assertArrayNotHasKey('payment_profile_id', $response->json('data'));
        $this->assertStringNotContainsString('agreed discount', $response->getContent());
    }
}
