<?php

namespace Tests\Feature\Payment;

use App\Jobs\SendAdminInvoicePaidNotificationJob;
use App\Models\Invoice;
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

class InvoiceCardPaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $client;
    private User $other_client;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin'],  ['display_name' => 'Admin',  'description' => 'Admin']);
        Role::firstOrCreate(['name' => 'client'], ['display_name' => 'Client', 'description' => 'Client']);

        $this->admin        = User::factory()->create(['is_active' => true]);
        $this->client       = User::factory()->create(['is_active' => true, 'stripe_customer_id' => 'cus_client']);
        $this->other_client = User::factory()->create(['is_active' => true]);

        $this->admin->assignRole('admin');
        $this->client->assignRole('client');
        $this->other_client->assignRole('client');

        Mail::fake();
        Bus::fake();
        Event::fake();
    }

    private function makeInvoice(array $overrides = []): Invoice
    {
        return Invoice::create(array_merge([
            'unique_id'       => strtoupper(bin2hex(random_bytes(4))),
            'invoice_number'  => 'BSM-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT),
            'user_id'         => $this->client->id,
            'status'          => 'unpaid',
            'currency_type'   => 'usd',
            'subtotal_amount' => 250.0,
            'discount_amount' => 0.0,
            'total_amount'    => 250.0,
            'credit_amount'   => 0.0,
            'sharing_enabled' => true,
            'share_key'       => 'share-token',
            'date_issued'     => now(),
            'date_due'        => now()->addDays(30),
        ], $overrides));
    }

    private function makePaymentProfile(User $user, array $overrides = []): PaymentProfile
    {
        return PaymentProfile::create(array_merge([
            'user_id'                  => $user->id,
            'stripe_payment_method_id' => 'pm_saved_' . bin2hex(random_bytes(3)),
            'card_brand'               => 'visa',
            'last_four'                => '4242',
            'expiry_month'             => '12',
            'expiry_year'              => (string) (now()->year + 2),
            'is_default'               => true,
        ], $overrides));
    }

    private function mockStripe(callable $configure): MockInterface
    {
        $mock = Mockery::mock(StripeService::class);
        $mock->shouldReceive('findOrCreateCustomer')->andReturn(['success' => true, 'customer_id' => 'cus_client'])->byDefault();
        $mock->shouldReceive('retrievePaymentMethod')->andReturn(['success' => true, 'customer_id' => 'cus_client', 'card' => []])->byDefault();
        $mock->shouldReceive('capturePaymentIntent')->andReturn(['success' => true])->byDefault();
        $mock->shouldReceive('cancelPaymentIntent')->andReturn(['success' => true, 'voided' => true])->byDefault();
        $configure($mock);
        $this->app->instance(StripeService::class, $mock);

        return $mock;
    }

    private function authHeader(): array
    {
        return ['Authorization' => 'Bearer dummy-token'];
    }

    // ─── Pay page: PaymentIntent (authenticated) ─────────────────────────────

    public function test_authenticated_payment_intent_uses_invoice_total_and_new_card(): void
    {
        $invoice = $this->makeInvoice();

        $this->mockStripe(function (MockInterface $mock) use ($invoice) {
            $mock->shouldReceive('createInvoiceCardPaymentIntent')
                ->once()
                ->withArgs(fn ($amount_cents, $metadata, $customer_id, $payment_method_id, $save_for_future) =>
                    $amount_cents === 25000
                    && $metadata['invoice_unique_id'] === $invoice->unique_id
                    && $customer_id === 'cus_client'
                    && $payment_method_id === null
                    && $save_for_future === true)
                ->andReturn(['success' => true, 'client_secret' => 'pi_1_secret', 'payment_intent_id' => 'pi_1']);
        });

        $this->actingAs($this->client, 'api')
            ->withHeaders($this->authHeader())
            ->postJson("/api/invoices/{$invoice->unique_id}/payment-intent", ['save_card' => true])
            ->assertOk()
            ->assertJsonPath('data.client_secret', 'pi_1_secret')
            ->assertJsonPath('data.payment_intent_id', 'pi_1');
    }

    public function test_authenticated_payment_intent_with_saved_card(): void
    {
        $invoice = $this->makeInvoice();
        $profile = $this->makePaymentProfile($this->client);

        $this->mockStripe(function (MockInterface $mock) use ($profile) {
            $mock->shouldReceive('createInvoiceCardPaymentIntent')
                ->once()
                ->withArgs(fn ($amount_cents, $metadata, $customer_id, $payment_method_id, $save_for_future) =>
                    $payment_method_id === $profile->stripe_payment_method_id
                    && $customer_id === 'cus_client'
                    && $save_for_future === false)
                ->andReturn(['success' => true, 'client_secret' => 'pi_2_secret', 'payment_intent_id' => 'pi_2']);
        });

        $this->actingAs($this->client, 'api')
            ->withHeaders($this->authHeader())
            ->postJson("/api/invoices/{$invoice->unique_id}/payment-intent", ['payment_profile_id' => $profile->id])
            ->assertOk()
            ->assertJsonPath('data.payment_intent_id', 'pi_2');
    }

    public function test_authenticated_payment_intent_rejects_another_users_saved_card(): void
    {
        $invoice = $this->makeInvoice();
        $profile = $this->makePaymentProfile($this->other_client);

        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldNotReceive('createInvoiceCardPaymentIntent'));

        $this->actingAs($this->client, 'api')
            ->withHeaders($this->authHeader())
            ->postJson("/api/invoices/{$invoice->unique_id}/payment-intent", ['payment_profile_id' => $profile->id])
            ->assertNotFound();
    }

    public function test_authenticated_payment_intent_rejects_expired_saved_card(): void
    {
        $invoice = $this->makeInvoice();
        $profile = $this->makePaymentProfile($this->client, ['expiry_month' => '01', 'expiry_year' => '2020']);

        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldNotReceive('createInvoiceCardPaymentIntent'));

        $this->actingAs($this->client, 'api')
            ->withHeaders($this->authHeader())
            ->postJson("/api/invoices/{$invoice->unique_id}/payment-intent", ['payment_profile_id' => $profile->id])
            ->assertStatus(422);
    }

    public function test_authenticated_payment_intent_for_another_clients_invoice_returns_403(): void
    {
        $invoice = $this->makeInvoice(['user_id' => $this->other_client->id]);

        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldNotReceive('createInvoiceCardPaymentIntent'));

        $this->actingAs($this->client, 'api')
            ->withHeaders($this->authHeader())
            ->postJson("/api/invoices/{$invoice->unique_id}/payment-intent")
            ->assertForbidden();
    }

    public function test_payment_intent_rejects_paid_and_credits_invoices(): void
    {
        $paid_invoice    = $this->makeInvoice(['status' => 'paid']);
        $credits_invoice = $this->makeInvoice(['currency_type' => 'credits', 'share_key' => 'credits-token']);

        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldNotReceive('createInvoiceCardPaymentIntent'));

        foreach ([$paid_invoice, $credits_invoice] as $invoice) {
            $this->actingAs($this->client, 'api')
                ->withHeaders($this->authHeader())
                ->postJson("/api/invoices/{$invoice->unique_id}/payment-intent")
                ->assertStatus(400);
        }
    }

    // ─── Pay page: PaymentIntent (public share link) ─────────────────────────

    public function test_public_payment_intent_with_valid_token_never_uses_saved_cards(): void
    {
        $invoice = $this->makeInvoice();
        $profile = $this->makePaymentProfile($this->client);

        $this->mockStripe(function (MockInterface $mock) {
            $mock->shouldReceive('createInvoiceCardPaymentIntent')
                ->once()
                ->withArgs(fn ($amount_cents, $metadata, $customer_id, $payment_method_id, $save_for_future) =>
                    $amount_cents === 25000
                    && $customer_id === null
                    && $payment_method_id === null
                    && $save_for_future === false)
                ->andReturn(['success' => true, 'client_secret' => 'pi_3_secret', 'payment_intent_id' => 'pi_3']);
        });

        $this->postJson("/api/invoices/{$invoice->unique_id}/payment-intent", [
            'token'              => 'share-token',
            'payment_profile_id' => $profile->id,
            'save_card'          => true,
        ])->assertOk()->assertJsonPath('data.payment_intent_id', 'pi_3');
    }

    public function test_public_payment_intent_with_invalid_token_returns_403(): void
    {
        $invoice = $this->makeInvoice();

        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldNotReceive('createInvoiceCardPaymentIntent'));

        $this->postJson("/api/invoices/{$invoice->unique_id}/payment-intent", ['token' => 'wrong'])
            ->assertForbidden();
    }

    public function test_public_payment_intent_without_token_returns_401(): void
    {
        $invoice = $this->makeInvoice();

        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldNotReceive('createInvoiceCardPaymentIntent'));

        $this->postJson("/api/invoices/{$invoice->unique_id}/payment-intent")
            ->assertUnauthorized();
    }

    // ─── Authenticated /pay: PaymentIntent must belong to the invoice ───────

    public function test_pay_rejects_payment_intent_created_for_another_invoice(): void
    {
        $invoice = $this->makeInvoice();

        $this->mockStripe(function (MockInterface $mock) {
            $mock->shouldReceive('verifyPaymentIntent')->andReturn([
                'verified' => true,
                'intent'   => (object) ['metadata' => ['invoice_unique_id' => 'OTHER123']],
            ]);
            $mock->shouldNotReceive('capturePaymentIntent');
        });

        $this->actingAs($this->client, 'api')
            ->withHeaders($this->authHeader())
            ->postJson("/api/invoices/{$invoice->unique_id}/pay", [
                'payment_method'    => 'credit_card',
                'payment_intent_id' => 'pi_other',
            ])->assertStatus(402);

        $this->assertSame('unpaid', $invoice->fresh()->status);
    }

    // ─── Admin: saved cards & charge card on file ────────────────────────────

    public function test_admin_can_list_invoice_clients_saved_cards(): void
    {
        $invoice = $this->makeInvoice();
        $this->makePaymentProfile($this->client);
        $this->makePaymentProfile($this->other_client);

        $this->actingAs($this->admin, 'api')
            ->getJson("/api/admin/invoices/{$invoice->id}/payment-profiles")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.last_four', '4242')
            ->assertJsonPath('data.0.is_expired', false)
            ->assertJsonMissingPath('data.0.stripe_payment_method_id');
    }

    public function test_client_cannot_access_admin_charge_endpoints(): void
    {
        $invoice = $this->makeInvoice();
        $profile = $this->makePaymentProfile($this->client);

        $this->actingAs($this->client, 'api')
            ->postJson("/api/admin/invoices/{$invoice->id}/charge-card", [
                'payment_profile_id' => $profile->id,
                'confirmation'       => true,
            ])->assertForbidden();
    }

    public function test_admin_charge_card_on_file_marks_invoice_paid(): void
    {
        $invoice = $this->makeInvoice();
        $profile = $this->makePaymentProfile($this->client);

        $this->mockStripe(function (MockInterface $mock) use ($profile, $invoice) {
            $mock->shouldReceive('chargeSavedCardOffSession')
                ->once()
                ->withArgs(fn ($amount_cents, $customer_id, $payment_method_id, $metadata) =>
                    $amount_cents === 25000
                    && $customer_id === 'cus_client'
                    && $payment_method_id === $profile->stripe_payment_method_id
                    && $metadata['invoice_unique_id'] === $invoice->unique_id)
                ->andReturn(['success' => true, 'payment_intent_id' => 'pi_admin']);
            $mock->shouldReceive('capturePaymentIntent')->once()->with('pi_admin')->andReturn(['success' => true]);
        });

        $this->actingAs($this->admin, 'api')
            ->postJson("/api/admin/invoices/{$invoice->id}/charge-card", [
                'payment_profile_id' => $profile->id,
                'confirmation'       => true,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('payment_method', 'Credit Card')
            ->assertJsonPath('payment_intent_id', 'pi_admin');

        $this->assertDatabaseHas('transactions', [
            'invoice_id'        => (string) $invoice->id,
            'payment_intent_id' => 'pi_admin',
            'type'              => 'purchase',
            'payment_method'    => 'credit_card',
        ]);
        $this->assertDatabaseHas('invoice_history', [
            'invoice_id' => $invoice->id,
            'event'      => 'invoice_paid',
            'actor_type' => 'admin',
        ]);
        Bus::assertDispatched(SendAdminInvoicePaidNotificationJob::class);
    }

    public function test_admin_charge_declined_card_keeps_invoice_unpaid(): void
    {
        $invoice = $this->makeInvoice();
        $profile = $this->makePaymentProfile($this->client);

        $this->mockStripe(function (MockInterface $mock) {
            $mock->shouldReceive('chargeSavedCardOffSession')->once()->andReturn([
                'success'    => false,
                'message'    => 'Your card was declined.',
                'error_code' => 'card_declined',
            ]);
            $mock->shouldNotReceive('capturePaymentIntent');
        });

        $this->actingAs($this->admin, 'api')
            ->postJson("/api/admin/invoices/{$invoice->id}/charge-card", [
                'payment_profile_id' => $profile->id,
                'confirmation'       => true,
            ])
            ->assertStatus(402)
            ->assertJsonPath('message', 'Your card was declined.');

        $this->assertSame('unpaid', $invoice->fresh()->status);
        $this->assertSame(0, Transaction::count());
    }

    public function test_admin_cannot_charge_another_clients_card(): void
    {
        $invoice = $this->makeInvoice();
        $profile = $this->makePaymentProfile($this->other_client);

        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldNotReceive('chargeSavedCardOffSession'));

        $this->actingAs($this->admin, 'api')
            ->postJson("/api/admin/invoices/{$invoice->id}/charge-card", [
                'payment_profile_id' => $profile->id,
                'confirmation'       => true,
            ])->assertNotFound();
    }

    public function test_admin_cannot_charge_paid_invoice(): void
    {
        $invoice = $this->makeInvoice(['status' => 'paid']);
        $profile = $this->makePaymentProfile($this->client);

        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldNotReceive('chargeSavedCardOffSession'));

        $this->actingAs($this->admin, 'api')
            ->postJson("/api/admin/invoices/{$invoice->id}/charge-card", [
                'payment_profile_id' => $profile->id,
                'confirmation'       => true,
            ])->assertStatus(400);
    }

    public function test_admin_charge_requires_confirmation(): void
    {
        $invoice = $this->makeInvoice();
        $profile = $this->makePaymentProfile($this->client);

        $this->actingAs($this->admin, 'api')
            ->postJson("/api/admin/invoices/{$invoice->id}/charge-card", [
                'payment_profile_id' => $profile->id,
            ])->assertStatus(422);
    }
}
