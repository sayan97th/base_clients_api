<?php

namespace Tests\Support\SeoSubscriptions;

use App\Jobs\SendEmailJob;
use App\Mail\SeoSubscriptionBillingEmail;
use App\Models\PaymentProfile;
use App\Models\SeoPackage;
use App\Models\SeoPackageSubscription;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\MockInterface;

/**
 * Shared fixtures for SEO subscription billing tests: clients, saved cards,
 * packages, subscriptions and a Stripe double. Time is frozen by the caller
 * (setUp) so billing dates are predictable.
 */
trait InteractsWithSeoSubscriptions
{
    protected function seedInvoiceNumberCounter(): void
    {
        if (DB::table('invoice_number_counters')->count() === 0) {
            DB::table('invoice_number_counters')->insert(['next_number' => 1000]);
        }
    }

    protected function makePackage(array $overrides = []): SeoPackage
    {
        return SeoPackage::create(array_merge([
            'id'              => 'growth',
            'name'            => 'Growth',
            'slug'            => 'growth',
            'price_per_month' => 1499,
            'best_for'        => 'Growing brands',
            'features'        => [],
            'is_active'       => true,
        ], $overrides));
    }

    protected function makeCardFor(User $user, array $overrides = []): PaymentProfile
    {
        return PaymentProfile::create(array_merge([
            'user_id'                  => $user->id,
            'stripe_payment_method_id' => 'pm_' . bin2hex(random_bytes(4)),
            'card_brand'               => 'visa',
            'last_four'                => '4242',
            'expiry_month'             => '12',
            'expiry_year'              => '2030',
            'is_default'               => true,
        ], $overrides));
    }

    protected function makeSubscriptionFor(User $user, array $overrides = []): SeoPackageSubscription
    {
        return SeoPackageSubscription::create(array_merge([
            'user_id'              => $user->id,
            'seo_package_id'       => null,
            'plan_name'            => 'Custom SEO Package',
            'pricing_type'         => 'custom',
            'monthly_price'        => 2000,
            'auto_renew'           => true,
            'billing_anchor_day'   => 1,
            'next_billing_date'    => '2026-12-01',
            'reminder_days_before' => 3,
            'payment_status'       => 'current',
            'status'               => 'active',
            'starts_at'            => '2026-11-01',
        ], $overrides));
    }

    /**
     * Binds a StripeService double. Customer lookups, captures and voids
     * succeed by default; $configure sets up the charge expectations.
     */
    protected function mockStripe(?callable $configure = null): MockInterface
    {
        $mock = Mockery::mock(StripeService::class);
        $mock->shouldReceive('findOrCreateCustomer')->andReturn(['success' => true, 'customer_id' => 'cus_client'])->byDefault();
        $mock->shouldReceive('retrievePaymentMethod')->andReturn(['success' => true, 'customer_id' => 'cus_client', 'card' => []])->byDefault();
        $mock->shouldReceive('capturePaymentIntent')->andReturn(['success' => true])->byDefault();
        $mock->shouldReceive('cancelPaymentIntent')->andReturn(['success' => true, 'voided' => true])->byDefault();

        if ($configure) {
            $configure($mock);
        }

        $this->app->instance(StripeService::class, $mock);

        return $mock;
    }

    protected function runBilling(array $options = []): void
    {
        $this->artisan('seo-subscriptions:process-billing', $options)->assertSuccessful();
    }

    /** Client billing emails of one variant queued through SendEmailJob. */
    protected function clientEmailsOf(string $variant): int
    {
        return Bus::dispatched(SendEmailJob::class, fn (SendEmailJob $job) =>
            $job->mailable instanceof SeoSubscriptionBillingEmail && $job->mailable->variant === $variant
        )->count();
    }
}
