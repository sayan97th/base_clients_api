<?php

namespace Tests\Feature\Admin\SeoPackages\Subscriptions;

use App\Models\Invoice;
use App\Models\SeoPackageSubscription;
use App\Models\SeoSubscriptionCharge;
use App\Models\Transaction;
use App\Models\User;
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
 * Same day activation (the admin's "today" never counts as the past), the
 * once per calendar month charge guard, payments recorded as paid outside
 * the portal, and every attempt being listed in Transactions.
 */
class SeoSubscriptionSameDayAndMonthlyGuardTest extends TestCase
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

    // ── Same day activation ────────────────────────────────────────────────

    public function test_today_in_the_admin_timezone_is_accepted_while_utc_is_already_tomorrow(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson(self::BASE_URL, $this->storePayload())
            ->assertCreated()
            ->assertJsonPath('data.next_billing_date', '2026-11-22');
    }

    public function test_without_a_timezone_the_billing_timezone_decides_today(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson(self::BASE_URL, $this->storePayload(['timezone' => null]))
            ->assertCreated();
    }

    public function test_a_real_past_date_is_still_rejected(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson(self::BASE_URL, $this->storePayload(['first_billing_date' => '2026-11-21']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['first_billing_date']);
    }

    public function test_charge_now_works_on_the_same_local_day(): void
    {
        $this->makeCardFor($this->client);

        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldReceive('chargeSavedCardOffSession')
            ->once()
            ->andReturn(['success' => true, 'payment_intent_id' => 'pi_today']));

        $this->actingAs($this->admin, 'api')
            ->postJson(self::BASE_URL, $this->storePayload(['charge_now' => true]))
            ->assertCreated()
            ->assertJsonPath('charge_result.success', true)
            ->assertJsonPath('data.next_billing_date', '2026-12-22');
    }

    public function test_a_same_day_subscription_charged_by_hand_is_not_charged_again_by_the_billing_run(): void
    {
        $this->makeCardFor($this->client);

        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldReceive('chargeSavedCardOffSession')
            ->once()
            ->andReturn(['success' => true, 'payment_intent_id' => 'pi_once']));

        // Activated without "Charge now", then charged by hand a bit later.
        $id = $this->actingAs($this->admin, 'api')
            ->postJson(self::BASE_URL, $this->storePayload(['auto_renew' => true]))
            ->assertCreated()
            ->json('data.id');

        Carbon::setTestNow('2026-11-23 14:00:00');
        $this->actingAs($this->admin, 'api')
            ->postJson(self::BASE_URL . "/{$id}/charge")
            ->assertOk();

        // The billing run later that day finds nothing due.
        Carbon::setTestNow('2026-11-23 20:05:00');
        $this->runBilling();

        $this->assertSame(1, SeoSubscriptionCharge::where('status', 'succeeded')->count());
        $this->assertSame(1, Transaction::where('type', 'purchase')->where('status', 'success')->count());
        $this->assertSame('2026-12-22', SeoPackageSubscription::findOrFail($id)->next_billing_date->toDateString());
    }

    // ── Once per calendar month ─────────────────────────────────────────────

    public function test_a_month_that_is_already_paid_is_never_charged_twice(): void
    {
        $this->makeCardFor($this->client);
        $subscription = $this->makeSubscriptionFor($this->client, [
            'billing_anchor_day' => 22,
            'next_billing_date'  => '2026-11-22',
        ]);

        SeoSubscriptionCharge::create([
            'seo_package_subscription_id' => $subscription->id,
            'user_id'                     => $this->client->id,
            'period_start'                => '2026-11-03',
            'period_end'                  => '2026-12-02',
            'amount'                      => 2000,
            'status'                      => 'succeeded',
            'trigger'                     => 'manual',
            'paid_at'                     => '2026-11-03 15:00:00',
        ]);

        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldReceive('chargeSavedCardOffSession')->never());

        Carbon::setTestNow('2026-11-23 15:05:00');
        $this->runBilling();

        $this->assertSame('2026-12-22', $subscription->refresh()->next_billing_date->toDateString());
        $this->assertSame(1, SeoSubscriptionCharge::count());
    }

    public function test_editing_the_next_billing_date_into_a_paid_month_is_rejected(): void
    {
        $subscription = $this->makeSubscriptionFor($this->client, ['next_billing_date' => '2026-12-01']);

        SeoSubscriptionCharge::create([
            'seo_package_subscription_id' => $subscription->id,
            'user_id'                     => $this->client->id,
            'period_start'                => '2026-11-01',
            'period_end'                  => '2026-11-30',
            'amount'                      => 2000,
            'status'                      => 'succeeded',
            'trigger'                     => 'automatic',
            'paid_at'                     => '2026-11-01 15:00:00',
        ]);

        $this->actingAs($this->admin, 'api')
            ->patchJson(self::BASE_URL . "/{$subscription->id}", [
                'next_billing_date' => '2026-11-28',
                'timezone'          => 'America/Denver',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['next_billing_date']);

        $this->actingAs($this->admin, 'api')
            ->patchJson(self::BASE_URL . "/{$subscription->id}", [
                'next_billing_date' => '2026-12-05',
                'timezone'          => 'America/Denver',
            ])
            ->assertOk();
    }

    // ── Paid outside the portal ─────────────────────────────────────────────

    public function test_first_period_paid_outside_is_recorded_and_never_charged(): void
    {
        $this->makeCardFor($this->client);
        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldReceive('chargeSavedCardOffSession')->never());

        $response = $this->actingAs($this->admin, 'api')
            ->postJson(self::BASE_URL, $this->storePayload([
                'auto_renew'                => true,
                'first_period_paid_outside' => true,
                'outside_payment_reference' => 'Bank transfer 8812',
            ]))
            ->assertCreated()
            ->assertJsonPath('charge_result.success', true)
            ->assertJsonPath('charge_result.paid_outside', true)
            ->assertJsonPath('data.next_billing_date', '2026-12-22');

        $charge = SeoSubscriptionCharge::firstOrFail();
        $this->assertSame('succeeded', $charge->status);
        $this->assertSame('external', $charge->trigger);
        $this->assertSame('paid', Invoice::findOrFail($charge->invoice_id)->status);

        $transaction = Transaction::firstOrFail();
        $this->assertSame('manual', $transaction->payment_method);
        $this->assertSame('success', $transaction->status);
        $this->assertSame((string) $response->json('data.id'), $transaction->metadata['seo_subscription_id']);
        $this->assertStringContainsString('Bank transfer 8812', $transaction->description);

        // The billing run on the same day does not touch the paid month.
        Carbon::setTestNow('2026-11-23 15:05:00');
        $this->runBilling();
        $this->assertSame(1, SeoSubscriptionCharge::count());
    }

    public function test_paid_outside_and_charge_now_cannot_be_combined(): void
    {
        $this->makeCardFor($this->client);

        $this->actingAs($this->admin, 'api')
            ->postJson(self::BASE_URL, $this->storePayload([
                'charge_now'                => true,
                'first_period_paid_outside' => true,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['first_period_paid_outside']);
    }

    // ── Transactions ────────────────────────────────────────────────────────

    public function test_a_declined_card_attempt_is_listed_in_transactions(): void
    {
        $this->makeCardFor($this->client);
        $subscription = $this->makeSubscriptionFor($this->client, [
            'billing_anchor_day' => 22,
            'next_billing_date'  => '2026-11-22',
        ]);

        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldReceive('chargeSavedCardOffSession')
            ->once()
            ->andReturn(['success' => false, 'message' => 'Your card has insufficient funds.', 'error_code' => 'insufficient_funds']));

        Carbon::setTestNow('2026-11-23 15:05:00');
        $this->runBilling();

        $transaction = Transaction::firstOrFail();
        $this->assertSame('failed_purchase', $transaction->type);
        $this->assertSame('failed', $transaction->status);
        $this->assertSame((string) $subscription->id, $transaction->metadata['seo_subscription_id']);
        $this->assertSame(SeoPackageSubscription::PAYMENT_STATUS_PAST_DUE, $subscription->refresh()->payment_status);
    }
}
