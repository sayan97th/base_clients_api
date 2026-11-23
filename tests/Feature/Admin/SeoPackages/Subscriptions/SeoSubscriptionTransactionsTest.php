<?php

namespace Tests\Feature\Admin\SeoPackages\Subscriptions;

use App\Jobs\SendAdminSeoSubscriptionBillingNotificationJob;
use App\Jobs\SendEmailJob;
use App\Models\Invoice;
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
 * Every payment of an SEO subscription must be listed in Transactions
 * (/api/admin/transactions): card renewals, manual charges, declined
 * attempts and months recorded as paid outside the portal. Each one carries
 * the subscription in its metadata so it can be traced back.
 */
class SeoSubscriptionTransactionsTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithAdminRoles;
    use InteractsWithSeoSubscriptions;

    private const SUBSCRIPTIONS_URL = '/api/admin/seo-packages/subscriptions';
    private const TRANSACTIONS_URL  = '/api/admin/transactions';

    private User $admin;
    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-22 15:00:00');

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

    private function activate(array $overrides = []): int
    {
        return $this->actingAs($this->admin, 'api')
            ->postJson(self::SUBSCRIPTIONS_URL, array_merge([
                'user_id'            => $this->client->id,
                'pricing_type'       => 'custom',
                'custom_price'       => 100,
                'starts_at'          => '2026-11-22',
                'first_billing_date' => '2026-11-22',
                'timezone'           => 'America/Denver',
                'auto_renew'         => true,
            ], $overrides))
            ->assertCreated()
            ->json('data.id');
    }

    private function dueSubscription()
    {
        return $this->makeSubscriptionFor($this->client, [
            'monthly_price'      => 100,
            'billing_anchor_day' => 22,
            'next_billing_date'  => '2026-11-22',
        ]);
    }

    private function approve(string $payment_intent_id = 'pi_ok'): array
    {
        return ['success' => true, 'payment_intent_id' => $payment_intent_id];
    }

    private function decline(): array
    {
        return ['success' => false, 'message' => 'Your card has insufficient funds.', 'error_code' => 'insufficient_funds', 'payment_intent_id' => 'pi_declined'];
    }

    // ── Card payments ───────────────────────────────────────────────────────

    public function test_charging_now_on_activation_is_listed_with_the_subscription(): void
    {
        $this->makeCardFor($this->client);
        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldReceive('chargeSavedCardOffSession')->once()->andReturn($this->approve('pi_now')));

        $id = $this->activate(['charge_now' => true]);

        $transaction = Transaction::sole();
        $this->assertSame('purchase', $transaction->type);
        $this->assertSame('success', $transaction->status);
        $this->assertSame('credit_card', $transaction->payment_method);
        $this->assertSame('pi_now', $transaction->payment_intent_id);
        $this->assertEquals(100.0, $transaction->amount);
        $this->assertSame($this->client->id, $transaction->user_id);
        $this->assertSame((string) $id, $transaction->metadata['seo_subscription_id']);
        $this->assertSame('manual', $transaction->metadata['charge_trigger']);
        $this->assertSame('2026-11-22', $transaction->metadata['billing_period']);
        $this->assertStringContainsString('SEO subscription November 2026', $transaction->description);
    }

    public function test_an_automatic_renewal_is_listed_as_automatic(): void
    {
        $this->makeCardFor($this->client);
        $subscription = $this->dueSubscription();
        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldReceive('chargeSavedCardOffSession')->once()->andReturn($this->approve()));

        $this->runBilling();

        $transaction = Transaction::sole();
        $this->assertSame('automatic', $transaction->metadata['charge_trigger']);
        $this->assertSame((string) $subscription->id, $transaction->metadata['seo_subscription_id']);
        $this->assertSame(
            (string) SeoSubscriptionCharge::sole()->invoice_id,
            $transaction->invoice_id
        );
    }

    public function test_a_charge_from_the_subscription_page_is_listed(): void
    {
        $this->makeCardFor($this->client);
        $subscription = $this->dueSubscription();
        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldReceive('chargeSavedCardOffSession')->once()->andReturn($this->approve()));

        $this->actingAs($this->admin, 'api')
            ->postJson(self::SUBSCRIPTIONS_URL . "/{$subscription->id}/charge")
            ->assertOk();

        $this->assertSame('manual', Transaction::sole()->metadata['charge_trigger']);
    }

    // ── Declined attempts ───────────────────────────────────────────────────

    public function test_a_declined_retry_and_the_successful_one_are_both_listed(): void
    {
        $this->makeCardFor($this->client);
        $this->dueSubscription();
        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldReceive('chargeSavedCardOffSession')
            ->twice()
            ->andReturn($this->decline(), $this->approve('pi_retry')));

        $this->runBilling();
        // First retry is 3 days after the decline.
        Carbon::setTestNow('2026-11-25 15:00:00');
        $this->runBilling();

        $this->assertSame(2, Transaction::count());
        $failed = Transaction::where('type', 'failed_purchase')->sole();
        $this->assertSame('failed', $failed->status);
        $this->assertSame('Your card has insufficient funds.', $failed->error_message);
        $this->assertSame('pi_declined', $failed->payment_intent_id);
        $this->assertSame('insufficient_funds', $failed->metadata['failure_code']);
        $this->assertStringContainsString('attempt 1', $failed->description);

        $paid = Transaction::where('type', 'purchase')->sole();
        $this->assertSame('pi_retry', $paid->payment_intent_id);
        $this->assertSame($failed->invoice_id, $paid->invoice_id);
    }

    public function test_a_declined_manual_charge_is_listed_as_well(): void
    {
        $this->makeCardFor($this->client);
        $subscription = $this->dueSubscription();
        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldReceive('chargeSavedCardOffSession')->once()->andReturn($this->decline()));

        $this->actingAs($this->admin, 'api')
            ->postJson(self::SUBSCRIPTIONS_URL . "/{$subscription->id}/charge")
            ->assertStatus(402);

        $failed = Transaction::sole();
        $this->assertSame('failed_purchase', $failed->type);
        $this->assertSame('manual', $failed->metadata['charge_trigger']);
        $this->assertStringContainsString('SEO subscription charge for November 2026 declined', $failed->description);
    }

    public function test_no_transaction_is_recorded_when_no_card_was_tried(): void
    {
        // Only an expired card: nothing is sent to Stripe, so there is no attempt to list.
        $this->makeCardFor($this->client, ['expiry_month' => '01', 'expiry_year' => '2020']);
        $this->dueSubscription();
        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldReceive('chargeSavedCardOffSession')->never());

        $this->runBilling();

        $this->assertSame('failed', SeoSubscriptionCharge::sole()->status);
        $this->assertSame(0, Transaction::count());
    }

    // ── Paid outside the portal ─────────────────────────────────────────────

    public function test_a_month_paid_outside_is_listed_as_a_manual_payment(): void
    {
        $id = $this->activate([
            'auto_renew'                => false,
            'first_period_paid_outside' => true,
            'outside_payment_reference' => 'Check 1042',
        ]);

        $transaction = Transaction::sole();
        $this->assertSame('purchase', $transaction->type);
        $this->assertSame('success', $transaction->status);
        $this->assertSame('manual', $transaction->payment_method);
        $this->assertNull($transaction->payment_intent_id);
        $this->assertSame((string) $id, $transaction->metadata['seo_subscription_id']);
        $this->assertSame('external', $transaction->metadata['charge_trigger']);
        $this->assertSame('Check 1042', $transaction->metadata['reference']);
        $this->assertSame((string) $this->admin->id, $transaction->metadata['recorded_by_admin_id']);

        $invoice = Invoice::findOrFail($transaction->invoice_id);
        $this->assertSame('paid', $invoice->status);
        $this->assertSame('Manual Payment', $invoice->payment_method);
        $this->assertSame($this->client->id, $invoice->user_id);
    }

    public function test_recording_a_month_paid_outside_sends_no_payment_emails(): void
    {
        $this->activate(['auto_renew' => false, 'first_period_paid_outside' => true]);

        Bus::assertNotDispatched(SendEmailJob::class);
        Bus::assertNotDispatched(SendAdminSeoSubscriptionBillingNotificationJob::class);
    }

    // ── Transactions endpoint ───────────────────────────────────────────────

    public function test_the_transactions_endpoint_lists_every_subscription_payment(): void
    {
        $this->makeCardFor($this->client);
        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldReceive('chargeSavedCardOffSession')
            ->twice()
            ->andReturn($this->decline(), $this->approve()));

        // Paid outside for November, then December declined and retried.
        $id = $this->activate(['first_period_paid_outside' => true]);
        Carbon::setTestNow('2026-12-22 15:00:00');
        $this->runBilling();
        Carbon::setTestNow('2026-12-25 15:00:00');
        $this->runBilling();

        $rows = collect(
            $this->actingAs($this->admin, 'api')->getJson(self::TRANSACTIONS_URL)->assertOk()->json('data')
        );

        $this->assertCount(3, $rows);
        $this->assertTrue($rows->every(fn ($row) => $row['metadata']['seo_subscription_id'] === (string) $id));
        $this->assertEqualsCanonicalizing(
            ['manual:purchase:success', 'credit_card:failed_purchase:failed', 'credit_card:purchase:success'],
            $rows->map(fn ($row) => "{$row['payment_method']}:{$row['type']}:{$row['status']}")->all()
        );
        $this->assertSame($this->client->email, $rows->first()['user']['email']);
    }

    public function test_the_transactions_endpoint_filters_manual_payments(): void
    {
        $this->makeCardFor($this->client);
        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldReceive('chargeSavedCardOffSession')->once()->andReturn($this->approve()));

        $this->activate(['first_period_paid_outside' => true]);
        $this->dueSubscription();
        $this->runBilling();

        $this->actingAs($this->admin, 'api')
            ->getJson(self::TRANSACTIONS_URL . '?payment_method=manual')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.payment_method', 'manual');
    }

    public function test_subscription_payments_can_be_searched(): void
    {
        $this->activate(['auto_renew' => false, 'first_period_paid_outside' => true, 'outside_payment_reference' => 'Wire 5531']);

        $this->actingAs($this->admin, 'api')
            ->getJson(self::TRANSACTIONS_URL . '?search=' . urlencode('SEO subscription'))
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($this->admin, 'api')
            ->getJson(self::TRANSACTIONS_URL . '?search=Wire%205531')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_clients_cannot_list_transactions(): void
    {
        $this->actingAs($this->client, 'api')->getJson(self::TRANSACTIONS_URL)->assertForbidden();
    }
}
