<?php

namespace Tests\Feature\Payment;

use App\Events\PaymentCompleted;
use App\Mail\PaymentSuccessfulEmail;
use App\Models\DrTier;
use App\Models\Invoice;
use App\Models\InvoiceHistory;
use App\Models\LinkBuildingOrder;
use App\Models\PaymentProfile;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Edge cases and end-to-end flows for invoice card payments:
 *   - pay page PaymentIntent creation (authenticated + public share link)
 *   - saved card customer resolution
 *   - admin "Charge Card on File" (permissions, failures, rollback, locking,
 *     notifications and payment_pending order transitions)
 */
class InvoiceCardPaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'staff', 'client'] as $role_name) {
            Role::firstOrCreate(['name' => $role_name], ['display_name' => ucfirst($role_name), 'description' => ucfirst($role_name)]);
        }

        $this->admin  = User::factory()->create(['is_active' => true, 'first_name' => 'Ada', 'last_name' => 'Admin']);
        $this->client = User::factory()->create(['is_active' => true, 'stripe_customer_id' => 'cus_client', 'credit_balance' => 100000]);

        $this->admin->assignRole('admin');
        $this->client->assignRole('client');

        Mail::fake();
        Bus::fake();
        // Only fake the notification event so Eloquent model events keep firing.
        Event::fake([PaymentCompleted::class]);
    }

    private function makeInvoice(array $overrides = []): Invoice
    {
        return Invoice::create(array_merge([
            'unique_id'       => strtoupper(bin2hex(random_bytes(4))),
            'invoice_number'  => 'BSM-' . str_pad((string) rand(1, 9999), 4, '0', STR_PAD_LEFT),
            'user_id'         => $this->client->id,
            'status'          => 'unpaid',
            'currency_type'   => 'usd',
            'subtotal_amount' => 120.0,
            'discount_amount' => 0.0,
            'total_amount'    => 120.0,
            'credit_amount'   => 0.0,
            'sharing_enabled' => true,
            'share_key'       => 'share-' . Str::random(12),
            'date_issued'     => now(),
            'date_due'        => now()->addDays(30),
        ], $overrides));
    }

    private function makePaymentProfile(array $overrides = []): PaymentProfile
    {
        return PaymentProfile::create(array_merge([
            'user_id'                  => $this->client->id,
            'stripe_payment_method_id' => 'pm_saved_' . Str::random(6),
            'card_brand'               => 'visa',
            'last_four'                => '4242',
            'expiry_month'             => '12',
            'expiry_year'              => (string) (now()->year + 3),
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

    private function chargeCard(Invoice $invoice, PaymentProfile $profile, ?User $actor = null)
    {
        return $this->actingAs($actor ?? $this->admin, 'api')
            ->postJson("/api/admin/invoices/{$invoice->id}/charge-card", [
                'payment_profile_id' => $profile->id,
                'confirmation'       => true,
            ]);
    }

    private function successfulCharge(MockInterface $mock, string $payment_intent_id = 'pi_admin'): void
    {
        $mock->shouldReceive('chargeSavedCardOffSession')
            ->once()
            ->andReturn(['success' => true, 'payment_intent_id' => $payment_intent_id]);
    }

    // ═══ Pay page: public share link ════════════════════════════════════════

    public function test_public_payment_intent_is_denied_when_sharing_is_disabled(): void
    {
        $invoice = $this->makeInvoice(['sharing_enabled' => false]);
        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldNotReceive('createInvoiceCardPaymentIntent'));

        $this->postJson("/api/invoices/{$invoice->unique_id}/payment-intent", ['token' => $invoice->share_key])
            ->assertForbidden();
    }

    public function test_public_payment_intent_for_unknown_invoice_returns_404(): void
    {
        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldNotReceive('createInvoiceCardPaymentIntent'));

        $this->postJson('/api/invoices/NOPE1234/payment-intent', ['token' => 'anything'])
            ->assertNotFound();
    }

    public function test_public_payment_intent_works_for_overdue_invoices(): void
    {
        $invoice = $this->makeInvoice(['status' => 'overdue']);

        $this->mockStripe(function (MockInterface $mock) use ($invoice) {
            $mock->shouldReceive('createInvoiceCardPaymentIntent')
                ->once()
                ->withArgs(fn ($amount_cents, $metadata) => $amount_cents === 12000
                    && $metadata['payment_source'] === 'public_share_link'
                    && $metadata['invoice_unique_id'] === $invoice->unique_id)
                ->andReturn(['success' => true, 'client_secret' => 'secret', 'payment_intent_id' => 'pi_pub']);
        });

        $this->postJson("/api/invoices/{$invoice->unique_id}/payment-intent", ['token' => $invoice->share_key])
            ->assertOk()
            ->assertJsonPath('data.payment_intent_id', 'pi_pub');
    }

    public function test_public_payment_intent_rejects_amounts_below_stripe_minimum(): void
    {
        $invoice = $this->makeInvoice(['total_amount' => 0.30, 'subtotal_amount' => 0.30]);
        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldNotReceive('createInvoiceCardPaymentIntent'));

        $this->postJson("/api/invoices/{$invoice->unique_id}/payment-intent", ['token' => $invoice->share_key])
            ->assertStatus(400);
    }

    public function test_public_payment_intent_ignores_client_supplied_amount(): void
    {
        $invoice = $this->makeInvoice();

        $this->mockStripe(function (MockInterface $mock) {
            $mock->shouldReceive('createInvoiceCardPaymentIntent')
                ->once()
                ->withArgs(fn ($amount_cents) => $amount_cents === 12000)
                ->andReturn(['success' => true, 'client_secret' => 'secret', 'payment_intent_id' => 'pi_pub']);
        });

        $this->postJson("/api/invoices/{$invoice->unique_id}/payment-intent", [
            'token'        => $invoice->share_key,
            'amount_cents' => 100,
        ])->assertOk();
    }

    // ═══ Pay page: authenticated client ═════════════════════════════════════

    public function test_inactive_user_cannot_create_payment_intent(): void
    {
        $invoice = $this->makeInvoice();
        $this->client->update(['is_active' => false]);
        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldNotReceive('createInvoiceCardPaymentIntent'));

        $this->actingAs($this->client, 'api')
            ->withHeaders(['Authorization' => 'Bearer dummy'])
            ->postJson("/api/invoices/{$invoice->unique_id}/payment-intent")
            ->assertUnauthorized();
    }

    public function test_authenticated_payment_intent_for_unknown_invoice_returns_404(): void
    {
        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldNotReceive('createInvoiceCardPaymentIntent'));

        $this->actingAs($this->client, 'api')
            ->withHeaders(['Authorization' => 'Bearer dummy'])
            ->postJson('/api/invoices/NOPE1234/payment-intent')
            ->assertNotFound();
    }

    public function test_stripe_failure_returns_502_with_generic_message(): void
    {
        $invoice = $this->makeInvoice();

        $this->mockStripe(function (MockInterface $mock) {
            $mock->shouldReceive('createInvoiceCardPaymentIntent')
                ->once()
                ->andReturn(['success' => false, 'message' => 'Internal Stripe detail sk_live_...']);
        });

        $response = $this->actingAs($this->client, 'api')
            ->withHeaders(['Authorization' => 'Bearer dummy'])
            ->postJson("/api/invoices/{$invoice->unique_id}/payment-intent")
            ->assertStatus(502);

        $this->assertStringNotContainsString('sk_live', $response->json('message'));
    }

    public function test_saved_card_attached_to_another_customer_is_rejected(): void
    {
        $invoice = $this->makeInvoice();
        $profile = $this->makePaymentProfile();

        $this->mockStripe(function (MockInterface $mock) {
            $mock->shouldReceive('retrievePaymentMethod')->andReturn(['success' => true, 'customer_id' => 'cus_somebody_else', 'card' => []]);
            $mock->shouldNotReceive('createInvoiceCardPaymentIntent');
        });

        $this->actingAs($this->client, 'api')
            ->withHeaders(['Authorization' => 'Bearer dummy'])
            ->postJson("/api/invoices/{$invoice->unique_id}/payment-intent", ['payment_profile_id' => $profile->id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This saved card is associated with a different account.');
    }

    public function test_saved_card_not_attached_to_any_customer_is_attached_first(): void
    {
        $invoice = $this->makeInvoice();
        $profile = $this->makePaymentProfile();

        $this->mockStripe(function (MockInterface $mock) use ($profile) {
            $mock->shouldReceive('retrievePaymentMethod')->andReturn(['success' => true, 'customer_id' => null, 'card' => []]);
            $mock->shouldReceive('attachPaymentMethod')
                ->once()
                ->with($profile->stripe_payment_method_id, 'cus_client')
                ->andReturn(['success' => true]);
            $mock->shouldReceive('createInvoiceCardPaymentIntent')
                ->once()
                ->andReturn(['success' => true, 'client_secret' => 'secret', 'payment_intent_id' => 'pi_x']);
        });

        $this->actingAs($this->client, 'api')
            ->withHeaders(['Authorization' => 'Bearer dummy'])
            ->postJson("/api/invoices/{$invoice->unique_id}/payment-intent", ['payment_profile_id' => $profile->id])
            ->assertOk();
    }

    public function test_saved_card_that_no_longer_exists_in_stripe_is_rejected(): void
    {
        $invoice = $this->makeInvoice();
        $profile = $this->makePaymentProfile();

        $this->mockStripe(function (MockInterface $mock) {
            $mock->shouldReceive('retrievePaymentMethod')->andReturn(['success' => false, 'message' => 'No such PaymentMethod']);
            $mock->shouldNotReceive('createInvoiceCardPaymentIntent');
        });

        $this->actingAs($this->client, 'api')
            ->withHeaders(['Authorization' => 'Bearer dummy'])
            ->postJson("/api/invoices/{$invoice->unique_id}/payment-intent", ['payment_profile_id' => $profile->id])
            ->assertStatus(422);
    }

    public function test_full_authenticated_saved_card_flow_marks_invoice_paid(): void
    {
        $invoice = $this->makeInvoice();
        $profile = $this->makePaymentProfile();

        $this->mockStripe(function (MockInterface $mock) use ($invoice) {
            $mock->shouldReceive('createInvoiceCardPaymentIntent')
                ->once()
                ->andReturn(['success' => true, 'client_secret' => 'secret', 'payment_intent_id' => 'pi_flow']);
            $mock->shouldReceive('verifyPaymentIntent')
                ->once()
                ->with('pi_flow', 120.0)
                ->andReturn([
                    'verified' => true,
                    'intent'   => (object) ['metadata' => ['invoice_unique_id' => $invoice->unique_id]],
                ]);
            $mock->shouldReceive('capturePaymentIntent')->once()->with('pi_flow')->andReturn(['success' => true]);
        });

        $this->actingAs($this->client, 'api')
            ->withHeaders(['Authorization' => 'Bearer dummy'])
            ->postJson("/api/invoices/{$invoice->unique_id}/payment-intent", ['payment_profile_id' => $profile->id])
            ->assertOk();

        // Stripe.js confirms the card on the client here, then the page records the payment.
        $this->actingAs($this->client, 'api')
            ->withHeaders(['Authorization' => 'Bearer dummy'])
            ->postJson("/api/invoices/{$invoice->unique_id}/pay", [
                'payment_method'    => 'credit_card',
                'payment_intent_id' => 'pi_flow',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');

        $this->assertSame('pi_flow', $invoice->fresh()->payment_intent_id);
        $this->assertSame('Credit Card', $invoice->fresh()->payment_method);
    }

    // ═══ Admin: listing saved cards ═════════════════════════════════════════

    public function test_payment_profiles_for_unknown_invoice_returns_404(): void
    {
        $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/invoices/' . Str::uuid() . '/payment-profiles')
            ->assertNotFound();
    }

    public function test_client_cannot_list_saved_cards_through_admin_endpoint(): void
    {
        $invoice = $this->makeInvoice();

        $this->actingAs($this->client, 'api')
            ->getJson("/api/admin/invoices/{$invoice->id}/payment-profiles")
            ->assertForbidden();
    }

    public function test_unauthenticated_requests_to_admin_endpoints_are_rejected(): void
    {
        $invoice = $this->makeInvoice();

        $this->getJson("/api/admin/invoices/{$invoice->id}/payment-profiles")->assertUnauthorized();
        $this->postJson("/api/admin/invoices/{$invoice->id}/charge-card", [])->assertUnauthorized();
    }

    // ═══ Admin: charge card on file ═════════════════════════════════════════

    public function test_staff_can_charge_card_on_file(): void
    {
        $staff = User::factory()->create(['is_active' => true]);
        $staff->assignRole('staff');

        $invoice = $this->makeInvoice();
        $profile = $this->makePaymentProfile();
        $this->mockStripe(fn (MockInterface $mock) => $this->successfulCharge($mock));

        $this->chargeCard($invoice, $profile, $staff)->assertOk()->assertJsonPath('status', 'paid');
    }

    public function test_charge_card_for_unknown_invoice_returns_404(): void
    {
        $profile = $this->makePaymentProfile();

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/invoices/' . Str::uuid() . '/charge-card', [
                'payment_profile_id' => $profile->id,
                'confirmation'       => true,
            ])->assertNotFound();
    }

    public function test_charge_card_requires_a_payment_profile(): void
    {
        $invoice = $this->makeInvoice();

        $this->actingAs($this->admin, 'api')
            ->postJson("/api/admin/invoices/{$invoice->id}/charge-card", ['confirmation' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_profile_id');
    }

    public function test_charge_card_rejects_expired_card(): void
    {
        $invoice = $this->makeInvoice();
        $profile = $this->makePaymentProfile(['expiry_month' => '01', 'expiry_year' => '2020']);
        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldNotReceive('chargeSavedCardOffSession'));

        $this->chargeCard($invoice, $profile)
            ->assertStatus(422)
            ->assertJsonPath('message', 'This card has expired. Please choose another card.');
    }

    public function test_charge_card_rejects_credits_invoice(): void
    {
        $invoice = $this->makeInvoice(['currency_type' => 'credits']);
        $profile = $this->makePaymentProfile();
        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldNotReceive('chargeSavedCardOffSession'));

        $this->chargeCard($invoice, $profile)->assertStatus(400);
    }

    public function test_charge_card_works_for_overdue_invoice(): void
    {
        $invoice = $this->makeInvoice(['status' => 'overdue']);
        $profile = $this->makePaymentProfile();
        $this->mockStripe(fn (MockInterface $mock) => $this->successfulCharge($mock));

        $this->chargeCard($invoice, $profile)->assertOk()->assertJsonPath('status', 'paid');
    }

    public function test_charge_card_rejects_card_attached_to_another_stripe_customer(): void
    {
        $invoice = $this->makeInvoice();
        $profile = $this->makePaymentProfile();

        $this->mockStripe(function (MockInterface $mock) {
            $mock->shouldReceive('retrievePaymentMethod')->andReturn(['success' => true, 'customer_id' => 'cus_other', 'card' => []]);
            $mock->shouldNotReceive('chargeSavedCardOffSession');
        });

        $this->chargeCard($invoice, $profile)->assertStatus(422);
        $this->assertSame('unpaid', $invoice->fresh()->status);
    }

    public function test_charge_card_sends_amount_metadata_and_admin_id_to_stripe(): void
    {
        $invoice = $this->makeInvoice(['total_amount' => 1234.56, 'subtotal_amount' => 1234.56]);
        $profile = $this->makePaymentProfile();

        $this->mockStripe(function (MockInterface $mock) use ($invoice, $profile) {
            $mock->shouldReceive('chargeSavedCardOffSession')
                ->once()
                ->withArgs(fn ($amount_cents, $customer_id, $payment_method_id, $metadata) =>
                    $amount_cents === 123456
                    && $customer_id === 'cus_client'
                    && $payment_method_id === $profile->stripe_payment_method_id
                    && $metadata['invoice_unique_id'] === $invoice->unique_id
                    && $metadata['invoice_number'] === $invoice->invoice_number
                    && $metadata['payment_source'] === 'admin_card_on_file'
                    && $metadata['charged_by_admin_id'] === (string) $this->admin->id)
                ->andReturn(['success' => true, 'payment_intent_id' => 'pi_admin']);
        });

        $this->chargeCard($invoice, $profile)->assertOk();
    }

    public function test_successful_charge_records_history_with_admin_and_card_details(): void
    {
        $invoice = $this->makeInvoice();
        $profile = $this->makePaymentProfile(['card_brand' => 'mastercard', 'last_four' => '5454']);
        $this->mockStripe(fn (MockInterface $mock) => $this->successfulCharge($mock));

        $this->chargeCard($invoice, $profile)->assertOk();

        $history = InvoiceHistory::where('invoice_id', $invoice->id)->where('event', 'invoice_paid')->firstOrFail();
        $this->assertSame($this->admin->id, $history->actor_id);
        $this->assertSame('admin', $history->actor_type);
        $this->assertStringContainsString('Mastercard ending in 5454', $history->description);
        $this->assertStringContainsString('pi_admin', $history->description);

        $this->assertNotNull($invoice->fresh()->date_paid);
        $this->assertSame(1, Transaction::where('invoice_id', (string) $invoice->id)->count());
        $this->assertSame($this->client->id, Transaction::firstOrFail()->user_id);
    }

    public function test_successful_charge_notifies_client_and_admins(): void
    {
        $invoice = $this->makeInvoice();
        $profile = $this->makePaymentProfile();
        $this->mockStripe(fn (MockInterface $mock) => $this->successfulCharge($mock));

        $this->chargeCard($invoice, $profile)->assertOk();

        Mail::assertQueued(PaymentSuccessfulEmail::class, fn ($mail) => $mail->hasTo($this->client->email));
        Event::assertDispatched(PaymentCompleted::class);
    }

    public function test_declined_charge_sends_no_notifications(): void
    {
        $invoice = $this->makeInvoice();
        $profile = $this->makePaymentProfile();

        $this->mockStripe(function (MockInterface $mock) {
            $mock->shouldReceive('chargeSavedCardOffSession')->once()->andReturn([
                'success' => false, 'message' => 'Declined.', 'error_code' => 'card_declined',
            ]);
        });

        $this->chargeCard($invoice, $profile)->assertStatus(402);

        Mail::assertNothingQueued();
        Event::assertNotDispatched(PaymentCompleted::class);
        $this->assertSame(0, InvoiceHistory::where('invoice_id', $invoice->id)->count());
    }

    public function test_db_failure_voids_the_authorization_and_keeps_invoice_unpaid(): void
    {
        $invoice = $this->makeInvoice();
        $profile = $this->makePaymentProfile();

        $this->mockStripe(function (MockInterface $mock) {
            $this->successfulCharge($mock, 'pi_rollback');
            $mock->shouldReceive('cancelPaymentIntent')->once()->with('pi_rollback')->andReturn(['success' => true, 'voided' => true]);
            $mock->shouldNotReceive('capturePaymentIntent');
        });

        Transaction::creating(function () {
            throw new \RuntimeException('Database unavailable');
        });

        $this->chargeCard($invoice, $profile)
            ->assertStatus(500)
            ->assertJsonPath('message', 'The payment could not be recorded. The card authorization has been voided and the card was not charged.');

        $fresh_invoice = $invoice->fresh();
        $this->assertSame('unpaid', $fresh_invoice->status);
        $this->assertNull($fresh_invoice->payment_intent_id);
        $this->assertSame(0, InvoiceHistory::where('invoice_id', $invoice->id)->count());
        Mail::assertNothingQueued();
    }

    public function test_capture_failure_after_commit_still_reports_paid(): void
    {
        $invoice = $this->makeInvoice();
        $profile = $this->makePaymentProfile();

        $this->mockStripe(function (MockInterface $mock) {
            $this->successfulCharge($mock);
            $mock->shouldReceive('capturePaymentIntent')->once()->andReturn(['success' => false, 'message' => 'Capture failed']);
            $mock->shouldNotReceive('cancelPaymentIntent');
        });

        $this->chargeCard($invoice, $profile)->assertOk()->assertJsonPath('status', 'paid');
    }

    public function test_concurrent_charge_is_rejected_while_lock_is_held(): void
    {
        $invoice = $this->makeInvoice();
        $profile = $this->makePaymentProfile();
        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldNotReceive('chargeSavedCardOffSession'));

        $held_lock = Cache::lock("invoice-card-charge:{$invoice->id}", 60);
        $this->assertTrue($held_lock->get());

        try {
            $this->chargeCard($invoice, $profile)
                ->assertStatus(409)
                ->assertJsonPath('message', 'A charge for this invoice is already in progress.');
        } finally {
            $held_lock->release();
        }
    }

    public function test_lock_is_released_after_a_failed_charge(): void
    {
        $invoice = $this->makeInvoice();
        $profile = $this->makePaymentProfile();

        $this->mockStripe(function (MockInterface $mock) {
            $mock->shouldReceive('chargeSavedCardOffSession')->twice()->andReturn(
                ['success' => false, 'message' => 'Declined.', 'error_code' => 'card_declined'],
                ['success' => true, 'payment_intent_id' => 'pi_second_try'],
            );
        });

        $this->chargeCard($invoice, $profile)->assertStatus(402);
        $this->chargeCard($invoice, $profile)->assertOk()->assertJsonPath('payment_intent_id', 'pi_second_try');
    }

    public function test_second_charge_after_success_is_rejected(): void
    {
        $invoice = $this->makeInvoice();
        $profile = $this->makePaymentProfile();
        $this->mockStripe(fn (MockInterface $mock) => $this->successfulCharge($mock));

        $this->chargeCard($invoice, $profile)->assertOk();
        $this->chargeCard($invoice, $profile)->assertStatus(400);

        $this->assertSame(1, Transaction::count());
    }

    // ═══ End to end: Pay Later checkout → admin charges card on file ════════

    private function createDeferredLinkBuildingInvoice(bool $complete_details): Invoice
    {
        $dr_tier = DrTier::create([
            'id' => 'dr30', 'label' => 'DR 30+', 'min_dr' => 30, 'max_dr' => 39, 'traffic_range' => '5k–10k',
            'word_count' => 500, 'price_per_link' => 100.0, 'is_hidden' => false, 'is_active' => true,
        ]);

        $placement = $complete_details
            ? ['row_index' => 0, 'keyword' => 'keyword 0', 'landing_page' => 'https://example.com/p0', 'exact_match' => false]
            : ['row_index' => 0, 'keyword' => null, 'landing_page' => null, 'exact_match' => false];

        $unique_id = $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout/deferred', [
                'deferred_payment'           => true,
                'total_amount'               => 100.0,
                'session_id'                 => (string) Str::uuid(),
                'order_title'                => 'Monthly Links',
                'order_notes'                => null,
                'coupon_ids'                 => [],
                'link_building_items'        => [[
                    'dr_tier_id' => $dr_tier->id,
                    'quantity'   => 1,
                    'unit_price' => 100.0,
                    'placements' => [$placement],
                ]],
                'content_optimization_items' => null,
                'new_content_items'          => null,
                'content_brief_items'        => null,
            ])
            ->assertOk()
            ->json('data.invoice_unique_id');

        return Invoice::where('unique_id', $unique_id)->firstOrFail();
    }

    public function test_admin_charge_moves_complete_payment_pending_orders_to_new_request(): void
    {
        $invoice = $this->createDeferredLinkBuildingInvoice(complete_details: true);
        $order   = LinkBuildingOrder::where('session_title', 'Monthly Links')->firstOrFail();
        $this->assertSame('payment_pending', $order->status);

        $profile = $this->makePaymentProfile();
        $this->mockStripe(fn (MockInterface $mock) => $this->successfulCharge($mock, 'pi_monthly'));

        $this->chargeCard($invoice, $profile)->assertOk();

        $order->refresh();
        $this->assertSame('new_request', $order->status);
        $this->assertSame('pi_monthly', $order->payment_intent_id);
    }

    public function test_admin_charge_moves_incomplete_payment_pending_orders_to_pending_details(): void
    {
        $invoice = $this->createDeferredLinkBuildingInvoice(complete_details: false);
        $profile = $this->makePaymentProfile();
        $this->mockStripe(fn (MockInterface $mock) => $this->successfulCharge($mock));

        $this->chargeCard($invoice, $profile)->assertOk();

        $this->assertSame(
            'pending_details',
            LinkBuildingOrder::where('session_title', 'Monthly Links')->firstOrFail()->status
        );
    }
}
