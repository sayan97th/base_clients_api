<?php

namespace Tests\Feature\Admin\SeoPackages\Subscriptions\PaymentHealth;

use App\Models\Invoice;
use App\Models\InvoiceHistory;
use App\Models\SeoSubscriptionCharge;
use Illuminate\Support\Carbon;
use Tests\Support\SeoSubscriptions\PaymentHealthTestCase;

/**
 * Admins can cancel a subscription at any time, including while a payment
 * is pending or the client is months behind. The unpaid invoices are kept
 * open by default so the balance can still be collected, or voided when the
 * admin writes the debt off.
 */
class CancelWithOutstandingBalanceTest extends PaymentHealthTestCase
{
    private function cancel(int $subscription_id, array $payload = [])
    {
        return $this->actingAs($this->admin, 'api')->patchJson(self::ADMIN_URL . "/{$subscription_id}/cancel", $payload);
    }

    /** A declined renewal with its unpaid invoice. */
    private function makeDeclinedSubscription()
    {
        $this->makeCardFor($this->client);
        $subscription = $this->makeSubscriptionFor($this->client);
        $this->declineEveryCharge();
        $this->runBilling();

        return $subscription->refresh();
    }

    public function test_a_past_due_subscription_can_be_cancelled(): void
    {
        $subscription = $this->makeDeclinedSubscription();
        $this->assertSame('past_due', $subscription->payment_status);

        $this->cancel($subscription->id, ['notes' => 'Client stopped paying.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.auto_renew', false)
            ->assertJsonPath('data.payment_health.state', 'inactive');

        $subscription->refresh();
        $this->assertNotNull($subscription->cancelled_at);
        $this->assertSame('Client stopped paying.', $subscription->notes);
    }

    public function test_a_subscription_months_behind_without_any_charge_can_be_cancelled(): void
    {
        $subscription = $this->makeSubscriptionFor($this->client, [
            'auto_renew'        => false,
            'next_billing_date' => '2026-08-01',
        ]);

        $this->cancel($subscription->id)->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_cancelling_keeps_unpaid_invoices_open_by_default_and_stops_retries(): void
    {
        $subscription = $this->makeDeclinedSubscription();

        $this->cancel($subscription->id)->assertOk();

        $this->assertSame('unpaid', Invoice::firstOrFail()->status);
        $this->assertNull(SeoSubscriptionCharge::firstOrFail()->next_retry_at);
        $this->assertSame('past_due', $subscription->refresh()->payment_status, 'The balance is still owed.');
    }

    public function test_a_cancelled_subscription_is_never_charged_again_by_the_scheduler(): void
    {
        $subscription = $this->makeDeclinedSubscription();
        $this->cancel($subscription->id)->assertOk();

        Carbon::setTestNow('2026-12-04 15:00:00');
        $this->mockStripe(fn ($mock) => $mock->shouldNotReceive('chargeSavedCardOffSession'));
        $this->runBilling();

        $this->assertSame(1, SeoSubscriptionCharge::firstOrFail()->attempt_count);
    }

    public function test_cancelling_can_void_the_unpaid_invoices(): void
    {
        $subscription = $this->makeDeclinedSubscription();

        $this->cancel($subscription->id, ['void_open_invoices' => true])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $invoice = Invoice::firstOrFail();
        $this->assertSame('void', $invoice->status);

        $history = InvoiceHistory::where('invoice_id', $invoice->id)->where('event', 'invoice voided')->firstOrFail();
        $this->assertSame($this->admin->id, $history->actor_id);
        $this->assertSame('admin', $history->actor_type);
        $this->assertSame('Invoice voided when the SEO subscription was cancelled.', $history->description);

        $this->assertSame('current', $subscription->refresh()->payment_status, 'Nothing is owed once the invoices are voided.');
    }

    public function test_voiding_never_touches_paid_invoices(): void
    {
        $this->makeCardFor($this->client);
        $subscription = $this->makeSubscriptionFor($this->client);
        $this->approveEveryCharge();
        $this->runBilling();

        $paid_invoice = Invoice::firstOrFail();
        $this->assertSame('paid', $paid_invoice->status);

        $this->cancel($subscription->id, ['void_open_invoices' => true])->assertOk();

        $this->assertSame('paid', $paid_invoice->refresh()->status);
    }

    public function test_voiding_only_touches_this_subscriptions_invoices(): void
    {
        $subscription = $this->makeDeclinedSubscription();

        $other_client = $this->makeUserWithRole('client');
        $this->makeCardFor($other_client);
        $other = $this->makeSubscriptionFor($other_client);
        $this->runBilling();
        $other_invoice = $other->charges()->firstOrFail()->invoice;

        $this->cancel($subscription->id, ['void_open_invoices' => true])->assertOk();

        $this->assertSame('unpaid', $other_invoice->refresh()->status);
    }

    public function test_void_open_invoices_must_be_a_boolean(): void
    {
        $subscription = $this->makeDeclinedSubscription();

        $this->cancel($subscription->id, ['void_open_invoices' => 'yes please'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('void_open_invoices');

        $this->assertSame('active', $subscription->refresh()->status);
    }

    public function test_an_already_cancelled_subscription_can_not_be_cancelled_again(): void
    {
        $subscription = $this->makeDeclinedSubscription();
        $this->cancel($subscription->id)->assertOk();

        $this->cancel($subscription->id, ['void_open_invoices' => true])->assertUnprocessable();

        $this->assertSame('unpaid', Invoice::firstOrFail()->status, 'A second request never voids anything.');
    }

    public function test_clients_can_not_cancel(): void
    {
        $subscription = $this->makeDeclinedSubscription();

        $this->actingAs($this->client, 'api')
            ->patchJson(self::ADMIN_URL . "/{$subscription->id}/cancel", ['void_open_invoices' => true])
            ->assertForbidden();

        $this->assertSame('active', $subscription->refresh()->status);
    }
}
