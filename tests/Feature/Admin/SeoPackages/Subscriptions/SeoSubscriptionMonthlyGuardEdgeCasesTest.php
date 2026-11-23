<?php

namespace Tests\Feature\Admin\SeoPackages\Subscriptions;

use App\Models\InvoiceHistory;
use App\Models\SeoPackageSubscription;
use App\Models\SeoSubscriptionCharge;
use App\Models\Transaction;
use App\Models\User;
use App\Services\SeoSubscriptionBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Mockery\MockInterface;
use Tests\Support\Admin\InteractsWithAdminRoles;
use Tests\Support\SeoSubscriptions\InteractsWithSeoSubscriptions;
use Tests\TestCase;

/**
 * Edge cases of same day billing and the once per calendar month guard:
 * timezones on create and edit, skipping several paid months, the manual
 * charge endpoint, and the rules for recording a month paid outside.
 */
class SeoSubscriptionMonthlyGuardEdgeCasesTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithAdminRoles;
    use InteractsWithSeoSubscriptions;

    private const BASE_URL = '/api/admin/seo-packages/subscriptions';

    private User $admin;
    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        // 01:30 UTC on Nov 23 is still the evening of Nov 22 in the US.
        Carbon::setTestNow('2026-11-23 01:30:00');
        config(['app.billing_timezone' => 'America/Denver']);

        $this->seedAdminRoles();
        $this->seedInvoiceNumberCounter();

        $this->admin  = $this->makeUserWithRole('admin');
        $this->client = $this->makeUserWithRole('client', ['stripe_customer_id' => 'cus_client']);

        Mail::fake();
        Bus::fake();
        Event::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function storePayload(array $overrides = []): array
    {
        return array_merge([
            'user_id'            => $this->client->id,
            'pricing_type'       => 'custom',
            'custom_price'       => 100,
            'starts_at'          => '2026-11-22',
            'first_billing_date' => '2026-11-22',
            'timezone'           => 'America/Denver',
            'auto_renew'         => false,
        ], $overrides);
    }

    private function paidCharge(SeoPackageSubscription $subscription, string $period_start, string $period_end): SeoSubscriptionCharge
    {
        return SeoSubscriptionCharge::create([
            'seo_package_subscription_id' => $subscription->id,
            'user_id'                     => $subscription->user_id,
            'period_start'                => $period_start,
            'period_end'                  => $period_end,
            'amount'                      => $subscription->monthly_price,
            'status'                      => SeoSubscriptionCharge::STATUS_SUCCEEDED,
            'trigger'                     => SeoSubscriptionCharge::TRIGGER_MANUAL,
            'paid_at'                     => $period_start . ' 15:00:00',
        ]);
    }

    private function billingService(): SeoSubscriptionBillingService
    {
        return app(SeoSubscriptionBillingService::class);
    }

    // ── Timezones on create ─────────────────────────────────────────────────

    public function test_the_admin_timezone_decides_today_not_utc(): void
    {
        // Same instant, same date: UTC already moved on to Nov 23.
        $this->actingAs($this->admin, 'api')
            ->postJson(self::BASE_URL, $this->storePayload(['timezone' => 'UTC']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.first_billing_date.0', 'The first billing date cannot be in the past.');

        $this->actingAs($this->admin, 'api')
            ->postJson(self::BASE_URL, $this->storePayload(['timezone' => 'America/Los_Angeles']))
            ->assertCreated();
    }

    public function test_an_unknown_timezone_falls_back_to_the_billing_timezone(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson(self::BASE_URL, $this->storePayload(['timezone' => 'Not/AZone']))
            ->assertCreated();
    }

    public function test_charge_now_is_refused_when_the_first_payment_is_tomorrow_for_the_admin(): void
    {
        $this->makeCardFor($this->client);

        $this->actingAs($this->admin, 'api')
            ->postJson(self::BASE_URL, $this->storePayload(['first_billing_date' => '2026-11-23', 'charge_now' => true]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['charge_now']);
    }

    public function test_a_future_first_payment_is_accepted_and_not_charged(): void
    {
        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldReceive('chargeSavedCardOffSession')->never());

        $this->actingAs($this->admin, 'api')
            ->postJson(self::BASE_URL, $this->storePayload(['first_billing_date' => '2026-12-05']))
            ->assertCreated()
            ->assertJsonPath('data.next_billing_date', '2026-12-05')
            ->assertJsonPath('charge_result', null);
    }

    // ── Timezones on edit ───────────────────────────────────────────────────

    public function test_editing_the_next_billing_date_to_today_works_in_the_evening(): void
    {
        $subscription = $this->makeSubscriptionFor($this->client, ['next_billing_date' => '2026-12-01']);

        $this->actingAs($this->admin, 'api')
            ->patchJson(self::BASE_URL . "/{$subscription->id}", [
                'next_billing_date' => '2026-11-22',
                'timezone'          => 'America/Denver',
            ])
            ->assertOk()
            ->assertJsonPath('data.next_billing_date', '2026-11-22')
            ->assertJsonPath('data.billing_anchor_day', 22);
    }

    public function test_editing_the_next_billing_date_into_the_past_is_rejected(): void
    {
        $subscription = $this->makeSubscriptionFor($this->client, ['next_billing_date' => '2026-12-01']);

        $this->actingAs($this->admin, 'api')
            ->patchJson(self::BASE_URL . "/{$subscription->id}", [
                'next_billing_date' => '2026-11-21',
                'timezone'          => 'America/Denver',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.next_billing_date.0', 'The next billing date cannot be in the past.');
    }

    public function test_settings_unrelated_to_the_date_still_save_when_the_current_month_is_paid(): void
    {
        $subscription = $this->makeSubscriptionFor($this->client, ['next_billing_date' => '2026-12-01']);
        $this->paidCharge($subscription, '2026-11-01', '2026-11-30');

        $this->actingAs($this->admin, 'api')
            ->patchJson(self::BASE_URL . "/{$subscription->id}", ['auto_renew' => false])
            ->assertOk();
    }

    public function test_the_paid_month_error_names_the_month(): void
    {
        $subscription = $this->makeSubscriptionFor($this->client, ['next_billing_date' => '2026-12-01']);
        $this->paidCharge($subscription, '2026-11-01', '2026-11-30');

        $this->actingAs($this->admin, 'api')
            ->patchJson(self::BASE_URL . "/{$subscription->id}", ['next_billing_date' => '2026-11-30', 'timezone' => 'America/Denver'])
            ->assertUnprocessable()
            ->assertJsonPath(
                'errors.next_billing_date.0',
                'November 2026 is already paid. Pick a date in a later month so the client is not charged twice.'
            );
    }

    // ── Once per calendar month ─────────────────────────────────────────────

    public function test_the_billing_run_skips_every_month_that_is_already_paid(): void
    {
        $this->makeCardFor($this->client);
        $subscription = $this->makeSubscriptionFor($this->client, [
            'billing_anchor_day' => 22,
            'next_billing_date'  => '2026-11-22',
        ]);
        $this->paidCharge($subscription, '2026-11-02', '2026-12-01');
        $this->paidCharge($subscription, '2026-12-02', '2027-01-01');

        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldReceive('chargeSavedCardOffSession')->never());

        Carbon::setTestNow('2026-11-23 15:05:00');
        $this->artisan('seo-subscriptions:process-billing')
            ->expectsOutputToContain('1 attempted, 0 succeeded, 0 failed, 1 skipped')
            ->assertSuccessful();

        $this->assertSame('2027-01-22', $subscription->refresh()->next_billing_date->toDateString());
        $this->assertSame(2, SeoSubscriptionCharge::count());
        $this->assertSame(0, Transaction::count());
    }

    public function test_charge_now_on_a_paid_month_is_refused_with_already_paid(): void
    {
        $this->makeCardFor($this->client);
        $subscription = $this->makeSubscriptionFor($this->client, [
            'billing_anchor_day' => 22,
            'next_billing_date'  => '2026-11-22',
        ]);
        $this->paidCharge($subscription, '2026-11-03', '2026-12-02');

        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldReceive('chargeSavedCardOffSession')->never());

        $this->actingAs($this->admin, 'api')
            ->postJson(self::BASE_URL . "/{$subscription->id}/charge")
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'already_paid')
            ->assertJsonPath('message', 'November 2026 is already paid, so the client was not charged again. The next charge is on Dec 22, 2026.');

        $this->assertSame('2026-12-22', $subscription->refresh()->next_billing_date->toDateString());
    }

    public function test_a_failed_month_does_not_block_the_next_charge(): void
    {
        $this->makeCardFor($this->client);
        $subscription = $this->makeSubscriptionFor($this->client, [
            'billing_anchor_day' => 22,
            'next_billing_date'  => '2026-11-22',
        ]);
        SeoSubscriptionCharge::create([
            'seo_package_subscription_id' => $subscription->id,
            'user_id'                     => $this->client->id,
            'period_start'                => '2026-11-22',
            'period_end'                  => '2026-12-21',
            'amount'                      => 2000,
            'status'                      => SeoSubscriptionCharge::STATUS_FAILED,
            'trigger'                     => SeoSubscriptionCharge::TRIGGER_AUTOMATIC,
            'attempt_count'               => 1,
        ]);

        $this->assertNull($this->billingService()->findPaidChargeForMonth($subscription, '2026-11-22'));

        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldReceive('chargeSavedCardOffSession')
            ->once()
            ->andReturn(['success' => true, 'payment_intent_id' => 'pi_retry']));

        $this->actingAs($this->admin, 'api')
            ->postJson(self::BASE_URL . "/{$subscription->id}/charge")
            ->assertOk();
    }

    public function test_paying_in_advance_for_a_later_month_is_allowed(): void
    {
        $this->makeCardFor($this->client);
        $subscription = $this->makeSubscriptionFor($this->client, [
            'billing_anchor_day' => 22,
            'next_billing_date'  => '2026-12-22',
        ]);
        $this->paidCharge($subscription, '2026-11-22', '2026-12-21');

        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldReceive('chargeSavedCardOffSession')
            ->once()
            ->andReturn(['success' => true, 'payment_intent_id' => 'pi_december']));

        $this->actingAs($this->admin, 'api')
            ->postJson(self::BASE_URL . "/{$subscription->id}/charge")
            ->assertOk();

        $this->assertSame(2, SeoSubscriptionCharge::where('status', 'succeeded')->count());
        $this->assertSame('2027-01-22', $subscription->refresh()->next_billing_date->toDateString());
    }

    public function test_the_month_guard_belongs_to_each_subscription(): void
    {
        $first  = $this->makeSubscriptionFor($this->client, ['next_billing_date' => '2026-12-01']);
        $second = $this->makeSubscriptionFor($this->client, ['next_billing_date' => '2026-11-22']);
        $this->paidCharge($first, '2026-11-01', '2026-11-30');

        $this->assertNotNull($this->billingService()->findPaidChargeForMonth($first, '2026-11-22'));
        $this->assertNull($this->billingService()->findPaidChargeForMonth($second, '2026-11-22'));
        $this->assertNull($this->billingService()->findPaidChargeForMonth($first, '2027-11-22'));
    }

    // ── Paid outside the portal ─────────────────────────────────────────────

    public function test_a_month_paid_outside_needs_no_card(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson(self::BASE_URL, $this->storePayload(['first_period_paid_outside' => true]))
            ->assertCreated()
            ->assertJsonPath('charge_result.success', true)
            ->assertJsonPath('charge_result.message', 'November 2026 was recorded as paid. The client will not be charged for it.');
    }

    public function test_the_payment_reference_is_limited_to_120_characters(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson(self::BASE_URL, $this->storePayload([
                'first_period_paid_outside' => true,
                'outside_payment_reference' => str_repeat('a', 121),
            ]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.outside_payment_reference.0', 'The payment reference can be at most 120 characters.');
    }

    public function test_the_payment_reference_is_kept_in_the_invoice_history(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson(self::BASE_URL, $this->storePayload([
                'first_period_paid_outside' => true,
                'outside_payment_reference' => '  Bank transfer 8812  ',
            ]))
            ->assertCreated();

        $history = InvoiceHistory::where('event', 'invoice paid')->sole();
        $this->assertStringContainsString('recorded as paid outside the portal', $history->description);
        $this->assertStringContainsString('Reference: Bank transfer 8812.', $history->description);
        $this->assertSame($this->admin->id, $history->actor_id);
        $this->assertSame('admin', $history->actor_type);
    }

    public function test_a_month_that_is_already_paid_cannot_be_recorded_again(): void
    {
        $subscription = $this->makeSubscriptionFor($this->client, ['next_billing_date' => '2026-11-22']);
        $this->paidCharge($subscription, '2026-11-01', '2026-11-30');

        $result = $this->billingService()->recordPeriodPaidOutside($subscription, $this->admin);

        $this->assertFalse($result['success']);
        $this->assertSame(409, $result['status_code']);
        $this->assertSame(0, Transaction::count());
    }

    public function test_a_cancelled_subscription_cannot_record_a_payment(): void
    {
        $subscription = $this->makeSubscriptionFor($this->client, ['status' => 'cancelled']);

        $result = $this->billingService()->recordPeriodPaidOutside($subscription, $this->admin);

        $this->assertFalse($result['success']);
        $this->assertSame(422, $result['status_code']);
        $this->assertSame(0, SeoSubscriptionCharge::count());
    }

    public function test_a_month_paid_outside_counts_as_collected_and_shows_in_the_history(): void
    {
        $id = $this->actingAs($this->admin, 'api')
            ->postJson(self::BASE_URL, $this->storePayload(['first_period_paid_outside' => true]))
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->admin, 'api')
            ->getJson(self::BASE_URL . "/{$id}")
            ->assertOk()
            ->assertJsonPath('summary.total_collected', 100)
            ->assertJsonPath('summary.successful_count', 1)
            ->assertJsonPath('data.payment_status', 'current')
            ->assertJsonPath('data.charges.0.trigger', 'external')
            ->assertJsonPath('data.charges.0.status', 'succeeded')
            ->assertJsonPath('data.charges.0.invoice.status', 'paid')
            ->assertJsonPath('data.charges.0.charged_by.id', $this->admin->id)
            ->assertJsonPath('summary.upcoming.0.date', '2026-12-22');

        $this->actingAs($this->admin, 'api')
            ->getJson(self::BASE_URL . '/stats')
            ->assertOk()
            ->assertJsonPath('data.collected_this_month', 100);
    }

    public function test_a_month_paid_outside_is_not_flagged_as_payment_pending(): void
    {
        $id = $this->actingAs($this->admin, 'api')
            ->postJson(self::BASE_URL, $this->storePayload(['first_period_paid_outside' => true]))
            ->assertCreated()
            ->json('data.id');

        // Days later, still before the next billing date.
        Carbon::setTestNow('2026-12-10 15:00:00');

        $this->actingAs($this->admin, 'api')
            ->getJson(self::BASE_URL . '?payment_status=pending')
            ->assertOk()
            ->assertJsonPath('data.total', 0);

        $this->assertSame('healthy', $this->actingAs($this->admin, 'api')
            ->getJson(self::BASE_URL . "/{$id}")
            ->json('data.payment_health.state'));
    }
}
