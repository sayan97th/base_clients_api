<?php

namespace Tests\Feature\Payment;

use App\Mail\AdminAutopayChargeFailedNotification;
use App\Mail\AutopayChargeFailedClientNotification;
use App\Models\AutopaySetting;
use App\Models\EmailNotificationSetting;
use App\Models\Invoice;
use App\Models\InvoiceChargeAttempt;
use App\Models\PaymentProfile;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class AutopayAndSavedCardChargeTest extends TestCase
{
    use RefreshDatabase;

    private User $client;
    private User $admin;
    private User $staff;
    private PaymentProfile $card;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['client', 'admin', 'staff', 'super_admin'] as $role_name) {
            Role::firstOrCreate(['name' => $role_name], ['display_name' => ucfirst($role_name), 'description' => $role_name]);
        }

        $this->client = User::factory()->create(['is_active' => true, 'stripe_customer_id' => 'cus_client']);
        $this->client->assignRole('client');

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->staff = User::factory()->create(['is_active' => true]);
        $this->staff->assignRole('staff');

        EmailNotificationSetting::create([
            'notify_all_admins' => false,
            'enabled_user_ids'  => [$this->admin->id],
            'custom_emails'     => ['billing@example.com'],
        ]);

        $this->card = PaymentProfile::create([
            'user_id'                  => $this->client->id,
            'stripe_payment_method_id' => 'pm_saved_visa',
            'card_brand'               => 'visa',
            'last_four'                => '9150',
            'expiry_month'             => '07',
            'expiry_year'              => (string) (now()->year + 2),
            'is_default'               => true,
        ]);

        Mail::fake();
        Bus::fake();
        Event::fake();
    }

    private function makeInvoice(array $overrides = []): Invoice
    {
        return Invoice::create(array_merge([
            'unique_id'       => strtoupper(bin2hex(random_bytes(4))),
            'invoice_number'  => 'BSM-' . random_int(1000, 9999),
            'user_id'         => $this->client->id,
            'status'          => 'unpaid',
            'currency_type'   => 'usd',
            'subtotal_amount' => 2270.0,
            'discount_amount' => 0.0,
            'total_amount'    => 2270.0,
            'credit_amount'   => 0.0,
            'sharing_enabled' => true,
            'share_key'       => 'share-' . bin2hex(random_bytes(6)),
            'date_issued'     => now(),
            'date_due'        => now()->subHour(),
        ], $overrides));
    }

    private function enableAutopay(array $overrides = []): AutopaySetting
    {
        return AutopaySetting::create(array_merge([
            'user_id'            => $this->client->id,
            'payment_profile_id' => $this->card->id,
            'is_enabled'         => true,
            'enabled_at'         => now()->subDay(),
            'consent_text'       => AutopaySetting::CONSENT_TEXT,
        ], $overrides));
    }

    private function mockStripe(): MockInterface
    {
        $mock = Mockery::mock(StripeService::class);
        $this->app->instance(StripeService::class, $mock);

        return $mock;
    }

    private function successfulCharge(): array
    {
        return ['success' => true, 'payment_intent_id' => 'pi_autopay_ok', 'status' => 'succeeded'];
    }

    // ─── Pay page PaymentIntent ──────────────────────────────────────────────

    public function test_payment_intent_uses_invoice_amount_and_saved_card(): void
    {
        $invoice = $this->makeInvoice();
        $stripe  = $this->mockStripe();

        $stripe->shouldReceive('findOrCreateCustomer')->andReturn(['success' => true, 'customer_id' => 'cus_client']);
        $stripe->shouldReceive('createCardPaymentIntent')
            ->once()
            ->withArgs(fn ($amount_cents, $customer, $payment_method, $save) => $amount_cents === 227000
                && $customer === 'cus_client'
                && $payment_method === 'pm_saved_visa'
                && $save === false)
            ->andReturn(['success' => true, 'client_secret' => 'pi_x_secret', 'payment_intent_id' => 'pi_x']);

        $this->actingAs($this->client, 'api')
            ->withHeaders(['Authorization' => 'Bearer dummy'])
            ->postJson("/api/invoices/{$invoice->unique_id}/payment-intent", ['payment_profile_id' => $this->card->id])
            ->assertOk()
            ->assertJson(['client_secret' => 'pi_x_secret', 'amount_cents' => 227000]);
    }

    public function test_payment_intent_rejects_another_users_saved_card(): void
    {
        $other_user = User::factory()->create(['is_active' => true]);
        $other_card = PaymentProfile::create([
            'user_id'                  => $other_user->id,
            'stripe_payment_method_id' => 'pm_other',
            'card_brand'               => 'visa',
            'last_four'                => '1111',
            'expiry_month'             => '01',
            'expiry_year'              => (string) (now()->year + 1),
        ]);

        $invoice = $this->makeInvoice();
        $this->mockStripe()->shouldNotReceive('createCardPaymentIntent');

        $this->actingAs($this->client, 'api')
            ->withHeaders(['Authorization' => 'Bearer dummy'])
            ->postJson("/api/invoices/{$invoice->unique_id}/payment-intent", ['payment_profile_id' => $other_card->id])
            ->assertNotFound();
    }

    public function test_public_payment_intent_requires_valid_token(): void
    {
        $invoice = $this->makeInvoice();
        $this->mockStripe()->shouldNotReceive('createCardPaymentIntent');

        $this->postJson("/api/invoices/{$invoice->unique_id}/payment-intent", ['token' => 'wrong'])
            ->assertForbidden();
    }

    // ─── Client autopay settings ─────────────────────────────────────────────

    public function test_client_must_accept_consent_to_enable_autopay(): void
    {
        $this->actingAs($this->client, 'api')
            ->putJson('/api/autopay', ['payment_profile_id' => $this->card->id])
            ->assertUnprocessable();

        $this->actingAs($this->client, 'api')
            ->putJson('/api/autopay', ['payment_profile_id' => $this->card->id, 'consent_accepted' => true, 'max_amount' => 5000])
            ->assertOk()
            ->assertJsonPath('data.is_enabled', true)
            ->assertJsonPath('data.payment_profile.last_four', '9150');

        $setting = AutopaySetting::where('user_id', $this->client->id)->first();
        $this->assertNotNull($setting->enabled_at);
        $this->assertSame(AutopaySetting::CONSENT_TEXT, $setting->consent_text);
    }

    public function test_client_can_turn_autopay_off(): void
    {
        $this->enableAutopay();

        $this->actingAs($this->client, 'api')
            ->deleteJson('/api/autopay')
            ->assertOk()
            ->assertJsonPath('data.is_enabled', false);

        $this->assertDatabaseHas('autopay_settings', [
            'user_id'          => $this->client->id,
            'is_enabled'       => false,
            'disabled_by_type' => 'client',
        ]);
    }

    public function test_removing_the_autopay_card_turns_autopay_off(): void
    {
        $this->enableAutopay();
        $this->mockStripe()->shouldReceive('detachPaymentMethod');

        $this->actingAs($this->client, 'api')
            ->deleteJson("/api/payment-profiles/{$this->card->id}")
            ->assertOk()
            ->assertJsonPath('autopay_disabled', true);

        $this->assertDatabaseHas('autopay_settings', ['user_id' => $this->client->id, 'is_enabled' => false, 'disabled_by_type' => 'system']);
    }

    // ─── Autopay runner ──────────────────────────────────────────────────────

    public function test_autopay_charges_due_invoice_and_marks_it_paid(): void
    {
        $this->enableAutopay();
        $invoice = $this->makeInvoice();

        $this->mockStripe()->shouldReceive('chargeSavedCardOffSession')
            ->once()
            ->withArgs(fn ($amount_cents, $customer, $payment_method, $metadata, $description, $idempotency_key) => $amount_cents === 227000
                && $customer === 'cus_client'
                && $payment_method === 'pm_saved_visa'
                && $metadata['invoice_unique_id'] === $invoice->unique_id
                && str_starts_with($idempotency_key, "invoice-charge:{$invoice->id}:autopay:1"))
            ->andReturn($this->successfulCharge());

        $this->artisan('invoices:process-autopay')->assertSuccessful();

        $invoice->refresh();
        $this->assertSame('paid', $invoice->status);
        $this->assertSame('pi_autopay_ok', $invoice->payment_intent_id);

        $this->assertDatabaseHas('invoice_charge_attempts', [
            'invoice_id' => $invoice->id,
            'source'     => 'autopay',
            'status'     => 'succeeded',
        ]);

        $transaction = Transaction::where('invoice_id', (string) $invoice->id)->where('status', 'success')->first();
        $this->assertSame('autopay', $transaction->metadata['charge_source']);
    }

    public function test_autopay_never_charges_invoices_created_before_opt_in_or_not_yet_due(): void
    {
        $this->enableAutopay(['enabled_at' => now()]);

        $old_invoice = $this->makeInvoice();
        $old_invoice->forceFill(['created_at' => now()->subDays(5)])->save();

        $this->makeInvoice(['date_due' => now()->addDays(10)]);

        $this->mockStripe()->shouldNotReceive('chargeSavedCardOffSession');

        $this->artisan('invoices:process-autopay')->assertSuccessful();

        $this->assertSame(0, InvoiceChargeAttempt::count());
    }

    public function test_autopay_failure_is_logged_scheduled_for_retry_and_notifies_admins_and_client(): void
    {
        $this->enableAutopay();
        $invoice = $this->makeInvoice();

        $this->mockStripe()->shouldReceive('chargeSavedCardOffSession')->once()->andReturn([
            'success'           => false,
            'pending'           => false,
            'payment_intent_id' => 'pi_declined',
            'error_code'        => 'card_declined',
            'decline_code'      => 'insufficient_funds',
            'message'           => 'Your card has insufficient funds.',
        ]);

        $this->artisan('invoices:process-autopay')->assertSuccessful();

        $attempt = InvoiceChargeAttempt::where('invoice_id', $invoice->id)->first();
        $this->assertSame('failed', $attempt->status);
        $this->assertSame('insufficient_funds', $attempt->failure_code);
        $this->assertTrue($attempt->is_retryable);
        $this->assertNotNull($attempt->next_retry_at);
        $this->assertSame('unpaid', $invoice->fresh()->status);

        Mail::assertSent(AdminAutopayChargeFailedNotification::class, 2);
        Mail::assertSent(AdminAutopayChargeFailedNotification::class, fn ($mail) => $mail->hasTo('billing@example.com'));
        Mail::assertSent(AutopayChargeFailedClientNotification::class, fn ($mail) => $mail->hasTo($this->client->email));

        // Running again before the retry time must not charge again.
        $this->artisan('invoices:process-autopay')->assertSuccessful();
        $this->assertSame(1, InvoiceChargeAttempt::where('invoice_id', $invoice->id)->count());
    }

    public function test_hard_decline_is_not_retried(): void
    {
        $this->enableAutopay();
        $invoice = $this->makeInvoice();

        $this->mockStripe()->shouldReceive('chargeSavedCardOffSession')->once()->andReturn([
            'success' => false, 'pending' => false, 'payment_intent_id' => null,
            'error_code' => 'card_declined', 'decline_code' => 'stolen_card', 'message' => 'Stolen.',
        ]);

        $this->artisan('invoices:process-autopay')->assertSuccessful();
        $this->travel(5)->days();
        $this->artisan('invoices:process-autopay')->assertSuccessful();

        $attempt = InvoiceChargeAttempt::where('invoice_id', $invoice->id)->sole();
        $this->assertFalse($attempt->is_retryable);
    }

    public function test_invoice_above_autopay_limit_is_skipped_and_reported_once(): void
    {
        $this->enableAutopay(['max_amount' => 1000]);
        $invoice = $this->makeInvoice();

        $this->mockStripe()->shouldNotReceive('chargeSavedCardOffSession');

        $this->artisan('invoices:process-autopay')->assertSuccessful();
        $this->artisan('invoices:process-autopay')->assertSuccessful();

        $this->assertSame(1, InvoiceChargeAttempt::where('invoice_id', $invoice->id)->where('status', 'skipped')->count());
        Mail::assertSent(AutopayChargeFailedClientNotification::class, 1);
    }

    public function test_stale_processing_attempt_is_reconciled_from_stripe(): void
    {
        $this->enableAutopay();
        $invoice = $this->makeInvoice();

        $attempt = InvoiceChargeAttempt::create([
            'invoice_id'               => $invoice->id,
            'user_id'                  => $this->client->id,
            'payment_profile_id'       => $this->card->id,
            'source'                   => 'autopay',
            'attempt_number'           => 1,
            'status'                   => 'processing',
            'amount'                   => 2270,
            'card_brand'               => 'visa',
            'card_last_four'           => '9150',
            'stripe_payment_intent_id' => 'pi_crashed',
            'idempotency_key'          => "invoice-charge:{$invoice->id}:autopay:1",
        ]);
        $attempt->forceFill(['updated_at' => now()->subHour()])->saveQuietly();

        $stripe = $this->mockStripe();
        $stripe->shouldReceive('retrievePaymentIntent')->with('pi_crashed')->andReturn([
            'success'        => true,
            'payment_intent' => ['id' => 'pi_crashed', 'status' => 'succeeded'],
        ]);
        $stripe->shouldNotReceive('chargeSavedCardOffSession');

        $this->artisan('invoices:process-autopay')->assertSuccessful();

        $this->assertSame('succeeded', $attempt->fresh()->status);
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    // ─── Admin "Charge card on file" ─────────────────────────────────────────

    public function test_admin_can_charge_card_on_file(): void
    {
        $invoice = $this->makeInvoice(['date_due' => now()->addDays(10)]);

        $this->mockStripe()->shouldReceive('chargeSavedCardOffSession')->once()->andReturn($this->successfulCharge());

        $this->actingAs($this->admin, 'api')
            ->postJson("/api/admin/invoices/{$invoice->id}/charge-saved-card", [
                'payment_profile_id' => $this->card->id,
                'confirmation'       => true,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'succeeded')
            ->assertJsonPath('invoice.status', 'paid');

        $this->assertDatabaseHas('invoice_charge_attempts', [
            'invoice_id'      => $invoice->id,
            'source'          => 'admin',
            'initiated_by_id' => $this->admin->id,
        ]);
    }

    public function test_admin_charge_decline_returns_error_without_alert_emails(): void
    {
        $invoice = $this->makeInvoice();

        $this->mockStripe()->shouldReceive('chargeSavedCardOffSession')->once()->andReturn([
            'success' => false, 'pending' => false, 'payment_intent_id' => 'pi_x',
            'error_code' => 'card_declined', 'decline_code' => 'generic_decline', 'message' => 'Declined.',
        ]);

        $this->actingAs($this->admin, 'api')
            ->postJson("/api/admin/invoices/{$invoice->id}/charge-saved-card", [
                'payment_profile_id' => $this->card->id,
                'confirmation'       => true,
            ])
            ->assertStatus(402)
            ->assertJsonPath('status', 'failed');

        $this->assertSame('unpaid', $invoice->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_staff_cannot_charge_card_on_file(): void
    {
        $invoice = $this->makeInvoice();
        $this->mockStripe()->shouldNotReceive('chargeSavedCardOffSession');

        $this->actingAs($this->staff, 'api')
            ->postJson("/api/admin/invoices/{$invoice->id}/charge-saved-card", [
                'payment_profile_id' => $this->card->id,
                'confirmation'       => true,
            ])
            ->assertForbidden();
    }

    public function test_paid_invoice_cannot_be_charged(): void
    {
        $invoice = $this->makeInvoice(['status' => 'paid']);
        $this->mockStripe()->shouldNotReceive('chargeSavedCardOffSession');

        $this->actingAs($this->admin, 'api')
            ->postJson("/api/admin/invoices/{$invoice->id}/charge-saved-card", [
                'payment_profile_id' => $this->card->id,
                'confirmation'       => true,
            ])
            ->assertStatus(422);
    }
}
