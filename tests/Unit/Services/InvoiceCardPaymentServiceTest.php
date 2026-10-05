<?php

namespace Tests\Unit\Services;

use App\Models\Invoice;
use App\Models\PaymentProfile;
use App\Models\User;
use App\Services\InvoiceCardPaymentService;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class InvoiceCardPaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    private InvoiceCardPaymentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2026, 10, 15, 12));

        $this->app->instance(StripeService::class, Mockery::mock(StripeService::class));
        $this->service = $this->app->make(InvoiceCardPaymentService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function profile(string $expiry_month, string $expiry_year): PaymentProfile
    {
        return new PaymentProfile(['expiry_month' => $expiry_month, 'expiry_year' => $expiry_year]);
    }

    private function invoice(array $attributes = []): Invoice
    {
        return new Invoice(array_merge([
            'status'        => 'unpaid',
            'currency_type' => 'usd',
            'total_amount'  => 100.0,
        ], $attributes));
    }

    // ─── isPaymentProfileExpired ────────────────────────────────────────────

    public function test_card_expiring_this_month_is_still_valid(): void
    {
        $this->assertFalse($this->service->isPaymentProfileExpired($this->profile('10', '2026')));
    }

    public function test_card_that_expired_last_month_is_expired(): void
    {
        $this->assertTrue($this->service->isPaymentProfileExpired($this->profile('9', '2026')));
    }

    public function test_card_that_expired_last_year_is_expired(): void
    {
        $this->assertTrue($this->service->isPaymentProfileExpired($this->profile('12', '2025')));
    }

    public function test_future_card_is_valid(): void
    {
        $this->assertFalse($this->service->isPaymentProfileExpired($this->profile('01', '2027')));
    }

    public function test_card_with_missing_expiry_is_not_treated_as_expired(): void
    {
        $this->assertFalse($this->service->isPaymentProfileExpired($this->profile('', '')));
    }

    // ─── validateCardPayable ────────────────────────────────────────────────

    public function test_unpaid_and_overdue_usd_invoices_are_payable(): void
    {
        $this->assertNull($this->service->validateCardPayable($this->invoice()));
        $this->assertNull($this->service->validateCardPayable($this->invoice(['status' => 'overdue'])));
    }

    public function test_non_payable_statuses_are_rejected(): void
    {
        foreach (['paid', 'void', 'refund', 'partial_refund', 'dispute'] as $status) {
            $error = $this->service->validateCardPayable($this->invoice(['status' => $status]));

            $this->assertNotNull($error, "Status {$status} should not be payable");
            $this->assertSame(400, $error['status_code']);
        }
    }

    public function test_credits_invoices_are_rejected(): void
    {
        $error = $this->service->validateCardPayable($this->invoice(['currency_type' => 'credits']));

        $this->assertNotNull($error);
        $this->assertStringContainsString('credits', $error['message']);
    }

    public function test_amounts_below_stripe_minimum_are_rejected(): void
    {
        $this->assertNotNull($this->service->validateCardPayable($this->invoice(['total_amount' => 0.49])));
        $this->assertNull($this->service->validateCardPayable($this->invoice(['total_amount' => 0.50])));
    }

    // ─── listPaymentProfiles ────────────────────────────────────────────────

    public function test_list_payment_profiles_returns_default_first_and_hides_stripe_ids(): void
    {
        $user  = User::factory()->create();
        $other = User::factory()->create();

        PaymentProfile::create([
            'user_id' => $user->id, 'stripe_payment_method_id' => 'pm_old', 'card_brand' => 'mastercard',
            'last_four' => '5555', 'expiry_month' => '01', 'expiry_year' => '2020', 'is_default' => false,
        ]);
        PaymentProfile::create([
            'user_id' => $user->id, 'stripe_payment_method_id' => 'pm_default', 'card_brand' => 'visa',
            'last_four' => '4242', 'expiry_month' => '12', 'expiry_year' => '2030', 'is_default' => true,
        ]);
        PaymentProfile::create([
            'user_id' => $other->id, 'stripe_payment_method_id' => 'pm_other', 'card_brand' => 'visa',
            'last_four' => '0000', 'expiry_month' => '12', 'expiry_year' => '2030', 'is_default' => true,
        ]);

        $profiles = $this->service->listPaymentProfiles($user);

        $this->assertCount(2, $profiles);
        $this->assertSame('4242', $profiles[0]['last_four']);
        $this->assertTrue($profiles[0]['is_default']);
        $this->assertFalse($profiles[0]['is_expired']);
        $this->assertSame('5555', $profiles[1]['last_four']);
        $this->assertTrue($profiles[1]['is_expired']);

        foreach ($profiles as $profile) {
            $this->assertArrayNotHasKey('stripe_payment_method_id', $profile);
        }
    }
}
