<?php

namespace Tests\Feature\Admin\SeoPackages\Subscriptions\PaymentHealth;

use Illuminate\Support\Facades\DB;
use Tests\Support\SeoSubscriptions\PaymentHealthTestCase;

/**
 * Where admins and clients see the payment health: the admin list (filters,
 * stats, row state), the admin detail (health and open invoices) and the
 * client's active subscription.
 */
class PaymentHealthEndpointsTest extends PaymentHealthTestCase
{
    private function listIds(string $query): array
    {
        return collect($this->actingAs($this->admin, 'api')
            ->getJson(self::ADMIN_URL . $query)
            ->assertOk()
            ->json('data.data'))
            ->pluck('id')
            ->sort()
            ->values()
            ->all();
    }

    /** One subscription in every billing situation. */
    private function makeEverySituation(): array
    {
        $this->makeCardFor($this->client);

        return [
            'healthy'   => $this->makeSubscriptionFor($this->client, ['next_billing_date' => '2027-01-01']),
            'pending'   => $this->makeSubscriptionFor($this->client, ['auto_renew' => false, 'next_billing_date' => '2026-11-01']),
            'past_due'  => $this->makeSubscriptionFor($this->client, ['payment_status' => 'past_due']),
            'due_today' => $this->makeSubscriptionFor($this->client, ['next_billing_date' => '2026-12-01']),
            'cancelled' => $this->makeSubscriptionFor($this->client, ['status' => 'cancelled', 'payment_status' => 'past_due', 'next_billing_date' => '2026-10-01']),
            'ended'     => $this->makeSubscriptionFor($this->client, ['auto_renew' => false, 'next_billing_date' => '2026-11-01', 'ends_at' => '2026-10-15']),
        ];
    }

    // ── Admin list ──────────────────────────────────────────────────────────

    public function test_the_pending_filter_lists_active_subscriptions_with_a_passed_billing_date(): void
    {
        $subscriptions = $this->makeEverySituation();

        $this->assertSame([$subscriptions['pending']->id], $this->listIds('?payment_status=pending'));
    }

    public function test_the_past_due_filter_still_lists_declined_renewals(): void
    {
        $subscriptions = $this->makeEverySituation();

        $ids = $this->listIds('?payment_status=past_due');

        $this->assertContains($subscriptions['past_due']->id, $ids);
        $this->assertNotContains($subscriptions['pending']->id, $ids);
    }

    public function test_the_attention_filter_combines_pending_and_past_due_active_subscriptions(): void
    {
        $subscriptions = $this->makeEverySituation();

        $this->assertSame(
            collect([$subscriptions['pending']->id, $subscriptions['past_due']->id])->sort()->values()->all(),
            $this->listIds('?payment_status=attention')
        );
    }

    public function test_an_unknown_payment_filter_is_ignored(): void
    {
        $subscriptions = $this->makeEverySituation();

        $this->assertCount(count($subscriptions), $this->listIds('?payment_status=whatever'));
    }

    public function test_stats_count_failed_and_pending_payments(): void
    {
        $this->makeEverySituation();

        $this->actingAs($this->admin, 'api')
            ->getJson(self::ADMIN_URL . '/stats')
            ->assertOk()
            ->assertJsonPath('data.past_due', 1)
            ->assertJsonPath('data.payment_pending', 1)
            ->assertJsonPath('data.needs_attention', 2);
    }

    public function test_every_row_carries_its_payment_health(): void
    {
        $subscriptions = $this->makeEverySituation();

        $rows = collect($this->actingAs($this->admin, 'api')
            ->getJson(self::ADMIN_URL . '?per_page=50')
            ->assertOk()
            ->json('data.data'))
            ->keyBy('id');

        $this->assertSame('healthy', $rows[$subscriptions['healthy']->id]['payment_health']['state']);
        $this->assertSame('payment_pending', $rows[$subscriptions['pending']->id]['payment_health']['state']);
        $this->assertSame(1, $rows[$subscriptions['pending']->id]['payment_health']['overdue_periods']);
        $this->assertSame('inactive', $rows[$subscriptions['cancelled']->id]['payment_health']['state']);
    }

    public function test_the_list_checks_cards_with_one_query_for_the_whole_page(): void
    {
        foreach (range(1, 6) as $index) {
            $client = $this->makeUserWithRole('client');
            $this->makeCardFor($client);
            $this->makeSubscriptionFor($client, ['next_billing_date' => '2027-01-01']);
        }

        DB::enableQueryLog();
        $this->actingAs($this->admin, 'api')->getJson(self::ADMIN_URL)->assertOk();
        $card_queries = collect(DB::getQueryLog())
            ->filter(fn ($query) => str_contains($query['query'], 'from "payment_profiles"'))
            ->count();
        DB::disableQueryLog();

        $this->assertSame(1, $card_queries);
    }

    // ── Admin detail ────────────────────────────────────────────────────────

    public function test_the_detail_exposes_the_health_and_the_open_invoices(): void
    {
        $this->makeCardFor($this->client);
        $subscription = $this->makeSubscriptionFor($this->client);
        $this->declineEveryCharge();
        $this->runBilling();

        $response = $this->actingAs($this->admin, 'api')
            ->getJson(self::ADMIN_URL . "/{$subscription->id}")
            ->assertOk()
            ->assertJsonPath('data.payment_health.state', 'retrying')
            ->assertJsonPath('data.payment_health.failure.code', 'insufficient_funds')
            ->assertJsonPath('data.payment_health.failure.message', 'Your card has insufficient funds.')
            ->assertJsonCount(1, 'summary.open_invoices')
            ->assertJsonPath('summary.open_invoices.0.status', 'unpaid');

        $this->assertEquals(2000, $response->json('summary.open_invoices.0.total_amount'));
        $this->assertSame(
            $response->json('data.payment_health.open_invoice.id'),
            $response->json('summary.open_invoices.0.id')
        );
    }

    public function test_the_detail_has_no_open_invoices_when_everything_is_paid(): void
    {
        $this->makeCardFor($this->client);
        $subscription = $this->makeSubscriptionFor($this->client);
        $this->approveEveryCharge();
        $this->runBilling();

        $this->actingAs($this->admin, 'api')
            ->getJson(self::ADMIN_URL . "/{$subscription->id}")
            ->assertOk()
            ->assertJsonPath('data.payment_health.state', 'healthy')
            ->assertJsonCount(0, 'summary.open_invoices');
    }

    public function test_staff_can_read_the_payment_health(): void
    {
        $staff = $this->makeUserWithRole('staff');
        $subscription = $this->makeSubscriptionFor($this->client, ['auto_renew' => false, 'next_billing_date' => '2026-11-01']);

        $this->actingAs($staff, 'api')
            ->getJson(self::ADMIN_URL . "/{$subscription->id}")
            ->assertOk()
            ->assertJsonPath('data.payment_health.state', 'payment_pending');
    }

    // ── Client endpoint ─────────────────────────────────────────────────────

    public function test_the_client_sees_a_failed_payment_in_plain_language(): void
    {
        $this->makeCardFor($this->client);
        $this->makeSubscriptionFor($this->client);
        $this->declineEveryCharge();
        $this->runBilling();

        $response = $this->actingAs($this->client, 'api')
            ->getJson(self::CLIENT_URL)
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'past_due')
            ->assertJsonPath('data.payment_health.state', 'retrying')
            ->assertJsonPath('data.payment_health.failure.reason', 'Your bank declined the payment due to insufficient funds.')
            ->assertJsonPath('data.payment_health.failure.period_label', 'December 2026');

        $this->assertStringNotContainsString('Your card has insufficient funds.', $response->getContent());
        $this->assertStringNotContainsString('insufficient_funds', $response->getContent());
        $this->assertNotNull($response->json('data.payment_health.open_invoice.unique_id'));
    }

    public function test_the_client_sees_a_missing_card_before_the_renewal(): void
    {
        $this->makeSubscriptionFor($this->client, ['next_billing_date' => '2026-12-20']);

        $this->actingAs($this->client, 'api')
            ->getJson(self::CLIENT_URL)
            ->assertOk()
            ->assertJsonPath('data.payment_health.state', 'no_payment_method')
            ->assertJsonPath('data.payment_health.card_issue', 'missing')
            ->assertJsonPath('data.payment_health.has_usable_card', false);
    }

    public function test_the_client_only_ever_sees_their_own_subscription(): void
    {
        $other_client = $this->makeUserWithRole('client');
        $this->makeSubscriptionFor($other_client, ['payment_status' => 'past_due']);

        $this->actingAs($this->client, 'api')
            ->getJson(self::CLIENT_URL)
            ->assertOk()
            ->assertJsonPath('data', null);
    }
}
