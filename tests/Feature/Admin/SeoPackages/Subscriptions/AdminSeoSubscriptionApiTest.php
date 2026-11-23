<?php

namespace Tests\Feature\Admin\SeoPackages\Subscriptions;

use App\Models\Invoice;
use App\Models\SeoPackage;
use App\Models\SeoPackageSubscription;
use App\Models\SeoSubscriptionCharge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Admin\InteractsWithAdminRoles;
use Tests\Support\SeoSubscriptions\InteractsWithSeoSubscriptions;
use Tests\TestCase;

/**
 * Admin SEO subscription endpoints (/api/admin/seo-packages/subscriptions):
 * listing, stats, detail, activation, editing, manual charge and cancel,
 * including validation and role access.
 */
class AdminSeoSubscriptionApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithAdminRoles;
    use InteractsWithSeoSubscriptions;

    private const BASE_URL = '/api/admin/seo-packages/subscriptions';

    private User $admin;
    private User $client;
    private SeoPackage $package;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-22 15:00:00');

        $this->seedAdminRoles();
        $this->seedInvoiceNumberCounter();

        $this->admin   = $this->makeUserWithRole('admin');
        $this->client  = $this->makeUserWithRole('client', ['stripe_customer_id' => 'cus_client', 'company' => 'Printed Image']);
        $this->package = $this->makePackage();

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
            'custom_price'       => 2000,
            'starts_at'          => '2026-11-22',
            'first_billing_date' => '2026-12-01',
        ], $overrides);
    }

    // ── Access ──────────────────────────────────────────────────────────────

    public function test_clients_cannot_reach_any_subscription_endpoint(): void
    {
        $subscription = $this->makeSubscriptionFor($this->client);

        $this->actingAs($this->client, 'api')->getJson(self::BASE_URL)->assertForbidden();
        $this->actingAs($this->client, 'api')->getJson(self::BASE_URL . "/{$subscription->id}")->assertForbidden();
        $this->actingAs($this->client, 'api')->postJson(self::BASE_URL, $this->storePayload())->assertForbidden();
    }

    public function test_staff_can_read_but_cannot_change_billing(): void
    {
        $staff        = $this->makeUserWithRole('staff');
        $subscription = $this->makeSubscriptionFor($this->client);

        $this->actingAs($staff, 'api')->getJson(self::BASE_URL)->assertOk();
        $this->actingAs($staff, 'api')->getJson(self::BASE_URL . "/{$subscription->id}")->assertOk();

        $this->actingAs($staff, 'api')->postJson(self::BASE_URL, $this->storePayload())->assertForbidden();
        $this->actingAs($staff, 'api')->patchJson(self::BASE_URL . "/{$subscription->id}", ['auto_renew' => false])->assertForbidden();
        $this->actingAs($staff, 'api')->postJson(self::BASE_URL . "/{$subscription->id}/charge")->assertForbidden();
    }

    // ── Listing and stats ───────────────────────────────────────────────────

    public function test_index_searches_by_company_and_plan_name(): void
    {
        $other_client = $this->makeUserWithRole('client', ['company' => 'Acme Corp']);
        $this->makeSubscriptionFor($this->client, ['plan_name' => 'Custom Growth Plus']);
        $this->makeSubscriptionFor($other_client, ['plan_name' => 'Starter']);

        $this->actingAs($this->admin, 'api')
            ->getJson(self::BASE_URL . '?search=Printed')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.user.company', 'Printed Image');

        $this->actingAs($this->admin, 'api')
            ->getJson(self::BASE_URL . '?search=Growth%20Plus')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.plan_name', 'Custom Growth Plus');
    }

    public function test_index_filters_past_due_and_auto_renew(): void
    {
        $this->makeSubscriptionFor($this->client, ['payment_status' => 'past_due']);
        $this->makeSubscriptionFor($this->client, ['auto_renew' => false]);

        $this->actingAs($this->admin, 'api')
            ->getJson(self::BASE_URL . '?payment_status=past_due')
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.payment_status', 'past_due');

        $this->actingAs($this->admin, 'api')
            ->getJson(self::BASE_URL . '?auto_renew=false')
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.auto_renew', false);
    }

    public function test_index_sorts_by_monthly_price_and_includes_the_last_charge(): void
    {
        $cheap     = $this->makeSubscriptionFor($this->client, ['monthly_price' => 500]);
        $expensive = $this->makeSubscriptionFor($this->client, ['monthly_price' => 3000]);

        SeoSubscriptionCharge::create([
            'seo_package_subscription_id' => $expensive->id,
            'user_id'                     => $this->client->id,
            'period_start'                => '2026-11-01',
            'period_end'                  => '2026-11-30',
            'amount'                      => 3000,
            'status'                      => 'succeeded',
            'trigger'                     => 'automatic',
        ]);

        $this->actingAs($this->admin, 'api')
            ->getJson(self::BASE_URL . '?sort_field=monthly_price&sort_direction=asc')
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $cheap->id)
            ->assertJsonPath('data.data.1.id', $expensive->id)
            ->assertJsonPath('data.data.0.last_charge', null)
            ->assertJsonPath('data.data.1.last_charge.status', 'succeeded')
            ->assertJsonPath('data.data.1.last_charge.period_label', 'November 2026');
    }

    public function test_stats_report_recurring_revenue_and_billing_health(): void
    {
        $this->makeSubscriptionFor($this->client, ['monthly_price' => 2000, 'next_billing_date' => '2026-11-25']);
        $this->makeSubscriptionFor($this->client, ['monthly_price' => 999, 'payment_status' => 'past_due', 'next_billing_date' => '2026-11-01']);
        $this->makeSubscriptionFor($this->client, ['monthly_price' => 1499, 'auto_renew' => false, 'next_billing_date' => '2026-12-20']);
        $cancelled = $this->makeSubscriptionFor($this->client, ['monthly_price' => 5000, 'status' => 'cancelled']);

        SeoSubscriptionCharge::create([
            'seo_package_subscription_id' => $cancelled->id,
            'user_id'                     => $this->client->id,
            'period_start'                => '2026-11-01',
            'period_end'                  => '2026-11-30',
            'amount'                      => 750,
            'status'                      => 'succeeded',
            'trigger'                     => 'automatic',
            'paid_at'                     => '2026-11-02 10:00:00',
        ]);

        $this->actingAs($this->admin, 'api')
            ->getJson(self::BASE_URL . '/stats')
            ->assertOk()
            ->assertJsonPath('data.total', 4)
            ->assertJsonPath('data.active', 3)
            ->assertJsonPath('data.cancelled', 1)
            ->assertJsonPath('data.past_due', 1)
            ->assertJsonPath('data.auto_renew', 2)
            ->assertJsonPath('data.monthly_recurring', 4498)
            ->assertJsonPath('data.renewing_next_7_days', 1)
            ->assertJsonPath('data.collected_this_month', 750);
    }

    // ── Detail ──────────────────────────────────────────────────────────────

    public function test_show_returns_404_for_an_unknown_subscription(): void
    {
        $this->actingAs($this->admin, 'api')->getJson(self::BASE_URL . '/999')->assertNotFound();
    }

    public function test_show_returns_history_newest_first_with_summary(): void
    {
        $this->makeCardFor($this->client);
        $subscription = $this->makeSubscriptionFor($this->client, ['next_billing_date' => '2026-12-01']);

        foreach ([['2026-10-01', 'succeeded'], ['2026-11-01', 'failed']] as [$period_start, $status]) {
            SeoSubscriptionCharge::create([
                'seo_package_subscription_id' => $subscription->id,
                'user_id'                     => $this->client->id,
                'period_start'                => $period_start,
                'period_end'                  => Carbon::parse($period_start)->endOfMonth()->toDateString(),
                'amount'                      => 2000,
                'status'                      => $status,
                'trigger'                     => 'automatic',
            ]);
        }

        $this->actingAs($this->admin, 'api')
            ->getJson(self::BASE_URL . "/{$subscription->id}")
            ->assertOk()
            ->assertJsonPath('data.charges.0.period_start', '2026-11-01')
            ->assertJsonPath('data.charges.1.period_start', '2026-10-01')
            ->assertJsonPath('summary.total_collected', 2000)
            ->assertJsonPath('summary.successful_count', 1)
            ->assertJsonPath('summary.failed_count', 1)
            ->assertJsonPath('summary.card_on_file', true)
            ->assertJsonCount(3, 'summary.upcoming')
            ->assertJsonPath('summary.upcoming.0.date', '2026-12-01')
            ->assertJsonPath('summary.upcoming.2.date', '2027-02-01');
    }

    public function test_show_reconciles_an_invoice_the_client_paid_from_the_portal(): void
    {
        $subscription = $this->makeSubscriptionFor($this->client, ['next_billing_date' => '2026-11-01', 'payment_status' => 'past_due']);
        $invoice      = Invoice::create([
            'unique_id'       => 'ABCD1234',
            'invoice_number'  => 'BSM-5000',
            'user_id'         => $this->client->id,
            'status'          => 'paid',
            'currency_type'   => 'usd',
            'subtotal_amount' => 2000,
            'discount_amount' => 0,
            'total_amount'    => 2000,
            'credit_amount'   => 0,
            'date_issued'     => now(),
            'date_paid'       => now(),
        ]);
        SeoSubscriptionCharge::create([
            'seo_package_subscription_id' => $subscription->id,
            'user_id'                     => $this->client->id,
            'invoice_id'                  => $invoice->id,
            'period_start'                => '2026-11-01',
            'period_end'                  => '2026-11-30',
            'amount'                      => 2000,
            'status'                      => 'failed',
            'trigger'                     => 'automatic',
        ]);

        $this->actingAs($this->admin, 'api')
            ->getJson(self::BASE_URL . "/{$subscription->id}")
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'current')
            ->assertJsonPath('data.next_billing_date', '2026-12-01')
            ->assertJsonPath('data.charges.0.status', 'succeeded');
    }

    public function test_payment_profiles_lists_the_cards_of_a_client(): void
    {
        $this->makeCardFor($this->client, ['last_four' => '1111']);
        $this->makeCardFor($this->client, ['last_four' => '2222', 'is_default' => false, 'expiry_year' => '2020']);

        $this->actingAs($this->admin, 'api')
            ->getJson(self::BASE_URL . "/payment-profiles?user_id={$this->client->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.last_four', '1111')
            ->assertJsonPath('data.0.is_default', true)
            ->assertJsonPath('data.1.is_expired', true);

        $this->actingAs($this->admin, 'api')
            ->getJson(self::BASE_URL . '/payment-profiles?user_id=999')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // ── Activation ──────────────────────────────────────────────────────────

    public static function invalidStorePayloadProvider(): array
    {
        return [
            'package pricing without a package' => [['pricing_type' => 'package', 'custom_price' => null], 'package_id'],
            'custom pricing without an amount'  => [['custom_price' => null], 'custom_price'],
            'custom amount below Stripe minimum' => [['custom_price' => 0.3], 'custom_price'],
            'first payment in the past'         => [['first_billing_date' => '2026-11-01'], 'first_billing_date'],
            'end date before the start'         => [['ends_at' => '2026-11-01'], 'ends_at'],
            'reminder longer than four weeks'   => [['reminder_days_before' => 29], 'reminder_days_before'],
            'reminder with a fraction of a day' => [['reminder_days_before' => 2.5], 'reminder_days_before'],
            'unknown pricing type'              => [['pricing_type' => 'yearly'], 'pricing_type'],
        ];
    }

    #[DataProvider('invalidStorePayloadProvider')]
    public function test_store_rejects_invalid_input(array $overrides, string $field): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson(self::BASE_URL, $this->storePayload($overrides))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $this->assertSame(0, SeoPackageSubscription::count());
    }

    public function test_store_rejects_a_card_that_belongs_to_another_client(): void
    {
        $other_card = $this->makeCardFor($this->makeUserWithRole('client'));
        $this->makeCardFor($this->client);

        $this->actingAs($this->admin, 'api')
            ->postJson(self::BASE_URL, $this->storePayload(['auto_renew' => true, 'payment_profile_id' => $other_card->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment_profile_id']);
    }

    public function test_store_rejects_an_inactive_package(): void
    {
        $this->makePackage(['id' => 'legacy', 'slug' => 'legacy', 'name' => 'Legacy', 'is_active' => false]);

        $this->actingAs($this->admin, 'api')
            ->postJson(self::BASE_URL, $this->storePayload(['pricing_type' => 'package', 'package_id' => 'legacy']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['package_id']);
    }

    public function test_charge_now_needs_the_first_payment_to_be_today(): void
    {
        $this->makeCardFor($this->client);

        $this->actingAs($this->admin, 'api')
            ->postJson(self::BASE_URL, $this->storePayload(['charge_now' => true, 'first_billing_date' => '2026-12-01']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['charge_now']);
    }

    public function test_store_stores_the_schedule_and_reminder_choices(): void
    {
        $card = $this->makeCardFor($this->client);

        $this->actingAs($this->admin, 'api')
            ->postJson(self::BASE_URL, $this->storePayload([
                'pricing_type'             => 'custom',
                'package_id'               => $this->package->id,
                'custom_price'             => 1250.5,
                'invoice_item_label'       => '  SEO Retainer  ',
                'invoice_item_description' => '',
                'starts_at'                => '2026-11-01',
                'first_billing_date'       => '2026-12-15',
                'ends_at'                  => '2027-06-14',
                'auto_renew'               => true,
                'payment_profile_id'       => $card->id,
                'reminder_days_before'     => null,
            ]))
            ->assertCreated()
            ->assertJsonPath('data.plan_name', 'Growth')
            ->assertJsonPath('data.monthly_price', 1250.5)
            ->assertJsonPath('data.invoice_item_label', 'SEO Retainer')
            ->assertJsonPath('data.invoice_item_description', null)
            ->assertJsonPath('data.next_billing_date', '2026-12-15')
            ->assertJsonPath('data.billing_anchor_day', 15)
            ->assertJsonPath('data.reminder_days_before', null)
            ->assertJsonPath('data.package.id', 'growth')
            ->assertJsonPath('charge_result', null);
    }

    public function test_store_with_charge_now_bills_the_first_period_immediately(): void
    {
        $this->makeCardFor($this->client);
        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldReceive('chargeSavedCardOffSession')
            ->once()
            ->andReturn(['success' => true, 'payment_intent_id' => 'pi_first']));

        $response = $this->actingAs($this->admin, 'api')
            ->postJson(self::BASE_URL, $this->storePayload([
                'first_billing_date' => '2026-11-22',
                'auto_renew'         => true,
                'charge_now'         => true,
            ]))
            ->assertCreated()
            ->assertJsonPath('charge_result.success', true)
            ->assertJsonPath('data.next_billing_date', '2026-12-22');

        $charge = SeoSubscriptionCharge::firstOrFail();
        $this->assertSame('manual', $charge->trigger);
        $this->assertSame($this->admin->id, $charge->charged_by_id);
        $this->assertSame($response->json('data.id'), $charge->seo_package_subscription_id);
    }

    public function test_store_keeps_the_subscription_when_the_first_charge_is_declined(): void
    {
        $this->makeCardFor($this->client);
        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldReceive('chargeSavedCardOffSession')
            ->once()
            ->andReturn(['success' => false, 'message' => 'Your card was declined.', 'error_code' => 'card_declined']));

        $this->actingAs($this->admin, 'api')
            ->postJson(self::BASE_URL, $this->storePayload(['first_billing_date' => '2026-11-22', 'charge_now' => true]))
            ->assertCreated()
            ->assertJsonPath('charge_result.success', false)
            ->assertJsonPath('charge_result.message', 'Your card was declined.')
            ->assertJsonPath('data.payment_status', 'past_due');

        $this->assertSame(1, SeoPackageSubscription::count());
    }

    // ── Editing ─────────────────────────────────────────────────────────────

    public function test_update_can_change_one_setting_at_a_time(): void
    {
        $subscription = $this->makeSubscriptionFor($this->client, ['reminder_days_before' => 3]);

        $this->actingAs($this->admin, 'api')
            ->patchJson(self::BASE_URL . "/{$subscription->id}", ['reminder_days_before' => null])
            ->assertOk()
            ->assertJsonPath('data.reminder_days_before', null)
            ->assertJsonPath('data.monthly_price', 2000)
            ->assertJsonPath('data.auto_renew', true);
    }

    public function test_update_accepts_a_custom_reminder_up_to_the_limit(): void
    {
        $subscription = $this->makeSubscriptionFor($this->client, ['reminder_days_before' => 3]);

        $this->actingAs($this->admin, 'api')
            ->patchJson(self::BASE_URL . "/{$subscription->id}", ['reminder_days_before' => 10])
            ->assertOk()
            ->assertJsonPath('data.reminder_days_before', 10);

        $this->actingAs($this->admin, 'api')
            ->patchJson(self::BASE_URL . "/{$subscription->id}", ['reminder_days_before' => SeoPackageSubscription::MAX_REMINDER_DAYS])
            ->assertOk()
            ->assertJsonPath('data.reminder_days_before', SeoPackageSubscription::MAX_REMINDER_DAYS);

        $this->actingAs($this->admin, 'api')
            ->patchJson(self::BASE_URL . "/{$subscription->id}", ['reminder_days_before' => SeoPackageSubscription::MAX_REMINDER_DAYS + 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reminder_days_before');

        $this->assertSame(SeoPackageSubscription::MAX_REMINDER_DAYS, $subscription->refresh()->reminder_days_before);
    }

    public function test_moving_the_next_billing_date_moves_the_anchor_and_resets_the_reminder(): void
    {
        $subscription = $this->makeSubscriptionFor($this->client, ['last_reminder_sent_for' => '2026-12-01']);

        $this->actingAs($this->admin, 'api')
            ->patchJson(self::BASE_URL . "/{$subscription->id}", ['next_billing_date' => '2026-12-20'])
            ->assertOk()
            ->assertJsonPath('data.next_billing_date', '2026-12-20')
            ->assertJsonPath('data.billing_anchor_day', 20);

        $this->assertNull($subscription->refresh()->last_reminder_sent_for);
    }

    public function test_switching_to_package_pricing_uses_the_package_price(): void
    {
        $subscription = $this->makeSubscriptionFor($this->client);

        $this->actingAs($this->admin, 'api')
            ->patchJson(self::BASE_URL . "/{$subscription->id}", ['pricing_type' => 'package', 'package_id' => 'growth'])
            ->assertOk()
            ->assertJsonPath('data.pricing_type', 'package')
            ->assertJsonPath('data.monthly_price', 1499)
            ->assertJsonPath('data.plan_name', 'Growth');
    }

    public function test_update_rejects_turning_auto_renew_on_without_a_card(): void
    {
        $subscription = $this->makeSubscriptionFor($this->client, ['auto_renew' => false]);

        $this->actingAs($this->admin, 'api')
            ->patchJson(self::BASE_URL . "/{$subscription->id}", ['auto_renew' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['auto_renew']);
    }

    public function test_update_rejects_a_past_next_billing_date_and_a_foreign_card(): void
    {
        $subscription = $this->makeSubscriptionFor($this->client);
        $foreign_card = $this->makeCardFor($this->makeUserWithRole('client'));

        $this->actingAs($this->admin, 'api')
            ->patchJson(self::BASE_URL . "/{$subscription->id}", ['next_billing_date' => '2026-11-01'])
            ->assertJsonValidationErrors(['next_billing_date']);

        $this->actingAs($this->admin, 'api')
            ->patchJson(self::BASE_URL . "/{$subscription->id}", ['payment_profile_id' => $foreign_card->id])
            ->assertJsonValidationErrors(['payment_profile_id']);
    }

    public function test_cancelled_subscriptions_cannot_be_edited_charged_or_cancelled_again(): void
    {
        $subscription = $this->makeSubscriptionFor($this->client, ['status' => 'cancelled', 'auto_renew' => false]);

        $this->actingAs($this->admin, 'api')
            ->patchJson(self::BASE_URL . "/{$subscription->id}", ['notes' => 'Hi'])
            ->assertUnprocessable();

        $this->actingAs($this->admin, 'api')
            ->postJson(self::BASE_URL . "/{$subscription->id}/charge")
            ->assertUnprocessable();

        $this->actingAs($this->admin, 'api')
            ->patchJson(self::BASE_URL . "/{$subscription->id}/cancel")
            ->assertUnprocessable();
    }

    // ── Manual charge ───────────────────────────────────────────────────────

    public function test_charge_rejects_a_card_from_another_client(): void
    {
        $subscription = $this->makeSubscriptionFor($this->client);
        $foreign_card = $this->makeCardFor($this->makeUserWithRole('client'));

        $this->actingAs($this->admin, 'api')
            ->postJson(self::BASE_URL . "/{$subscription->id}/charge", ['payment_profile_id' => $foreign_card->id])
            ->assertNotFound();
    }

    public function test_charge_can_use_a_one_time_card_override(): void
    {
        $this->makeCardFor($this->client, ['stripe_payment_method_id' => 'pm_default']);
        $backup       = $this->makeCardFor($this->client, ['stripe_payment_method_id' => 'pm_backup', 'is_default' => false, 'last_four' => '9999']);
        $subscription = $this->makeSubscriptionFor($this->client, ['next_billing_date' => '2026-11-22']);

        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldReceive('chargeSavedCardOffSession')
            ->once()
            ->withArgs(fn (...$args) => $args[2] === 'pm_backup')
            ->andReturn(['success' => true, 'payment_intent_id' => 'pi_backup']));

        $this->actingAs($this->admin, 'api')
            ->postJson(self::BASE_URL . "/{$subscription->id}/charge", ['payment_profile_id' => $backup->id])
            ->assertOk()
            ->assertJsonPath('data.charges.0.card_label', 'Visa ending in 9999');
    }

    public function test_charging_early_bills_the_upcoming_period_and_moves_the_schedule(): void
    {
        $this->makeCardFor($this->client);
        $subscription = $this->makeSubscriptionFor($this->client, ['next_billing_date' => '2026-12-01', 'auto_renew' => false]);

        // The controller instance is reused between requests in one test, so
        // a single double answers both charges.
        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldReceive('chargeSavedCardOffSession')
            ->twice()
            ->andReturn(
                ['success' => true, 'payment_intent_id' => 'pi_early'],
                ['success' => true, 'payment_intent_id' => 'pi_january'],
            ));

        $this->actingAs($this->admin, 'api')
            ->postJson(self::BASE_URL . "/{$subscription->id}/charge")
            ->assertOk()
            ->assertJsonPath('data.charges.0.period_label', 'December 2026')
            ->assertJsonPath('data.next_billing_date', '2027-01-01');

        // A second click bills January, never December twice.
        $this->actingAs($this->admin, 'api')->postJson(self::BASE_URL . "/{$subscription->id}/charge")->assertOk();

        $this->assertSame(1, SeoSubscriptionCharge::whereDate('period_start', '2026-12-01')->count());
        $this->assertSame(1, SeoSubscriptionCharge::whereDate('period_start', '2027-01-01')->count());
    }

    // ── Packages ────────────────────────────────────────────────────────────

    public function test_a_package_with_an_active_subscription_cannot_be_deleted(): void
    {
        $this->makeSubscriptionFor($this->client, ['seo_package_id' => 'growth', 'pricing_type' => 'package']);

        $this->actingAs($this->admin, 'api')
            ->deleteJson('/api/admin/seo-packages/growth')
            ->assertStatus(409);

        $this->assertNotNull(SeoPackage::find('growth'));
    }

    public function test_deleting_a_package_keeps_the_history_of_ended_subscriptions(): void
    {
        $subscription = $this->makeSubscriptionFor($this->client, [
            'seo_package_id' => 'growth',
            'plan_name'      => 'Growth',
            'pricing_type'   => 'package',
            'status'         => 'cancelled',
        ]);

        $this->actingAs($this->admin, 'api')->deleteJson('/api/admin/seo-packages/growth')->assertNoContent();

        $subscription->refresh();
        $this->assertNull($subscription->seo_package_id);
        $this->assertSame('Growth', $subscription->displayPlanName());
    }
}
