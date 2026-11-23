<?php

namespace Tests\Support\SeoSubscriptions;

use App\Models\SeoPackageSubscription;
use App\Models\SeoSubscriptionCharge;
use App\Models\User;
use App\Services\SeoSubscriptionPaymentHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Mockery\MockInterface;
use Tests\Support\Admin\InteractsWithAdminRoles;
use Tests\TestCase;

/**
 * Base for the SEO subscription payment health tests (failed auto renewals,
 * pending payments, missing cards). Time is frozen on a renewal day, an admin
 * and a client exist, and mail, jobs and events are faked.
 *
 * 15:00 UTC is 08:00 in the billing timezone (America/Denver), so "today" is
 * the same calendar day for the app and for the billing calendar.
 */
abstract class PaymentHealthTestCase extends TestCase
{
    use RefreshDatabase;
    use InteractsWithAdminRoles;
    use InteractsWithSeoSubscriptions;

    protected const ADMIN_URL = '/api/admin/seo-packages/subscriptions';
    protected const CLIENT_URL = '/api/seo-packages/subscriptions/active';
    protected const TODAY = '2026-12-01 15:00:00';

    protected User $admin;
    protected User $client;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::TODAY);

        $this->seedAdminRoles();
        $this->seedInvoiceNumberCounter();

        $this->admin  = $this->makeUserWithRole('admin', ['first_name' => 'Ana', 'last_name' => 'Admin']);
        $this->client = $this->makeUserWithRole('client', [
            'first_name'         => 'Cathleen',
            'last_name'          => 'Siech',
            'company'            => 'Printed Image',
            'stripe_customer_id' => 'cus_client',
        ]);

        Mail::fake();
        Bus::fake();
        Event::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Fresh evaluation, never reusing a cached card lookup. */
    protected function health(SeoPackageSubscription $subscription): array
    {
        $service = app(SeoSubscriptionPaymentHealthService::class);
        $service->forgetCardStatus($subscription->user_id);

        return $service->evaluate($subscription->fresh());
    }

    protected function declineEveryCharge(string $error_code = 'insufficient_funds', string $message = 'Your card has insufficient funds.'): void
    {
        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldReceive('chargeSavedCardOffSession')
            ->andReturn(['success' => false, 'message' => $message, 'error_code' => $error_code]));
    }

    protected function approveEveryCharge(): void
    {
        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldReceive('chargeSavedCardOffSession')
            ->andReturn(['success' => true, 'payment_intent_id' => 'pi_' . bin2hex(random_bytes(4)), 'status' => 'requires_capture']));
    }

    /** A declined period, without going through Stripe. */
    protected function makeFailedCharge(SeoPackageSubscription $subscription, array $overrides = []): SeoSubscriptionCharge
    {
        $period_start = Carbon::parse($overrides['period_start'] ?? $subscription->next_billing_date);

        return SeoSubscriptionCharge::create(array_merge([
            'seo_package_subscription_id' => $subscription->id,
            'user_id'                     => $subscription->user_id,
            'period_start'                => $period_start->toDateString(),
            'period_end'                  => $subscription->periodEndFor($period_start)->toDateString(),
            'amount'                      => $subscription->monthly_price,
            'status'                      => SeoSubscriptionCharge::STATUS_FAILED,
            'trigger'                     => SeoSubscriptionCharge::TRIGGER_AUTOMATIC,
            'attempt_count'               => 1,
            'last_attempt_at'             => now(),
            'failure_code'                => 'card_declined',
            'failure_message'             => 'The card was declined.',
            'next_retry_at'               => now()->addDays(3),
        ], $overrides));
    }
}
