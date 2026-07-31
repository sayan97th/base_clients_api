<?php

namespace Tests\Unit;

use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceNumberGenerator;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression coverage for the "duplicate entry 'BSM-0927' for key
 * invoices_invoice_number_unique" bug. Invoice numbers used to be computed
 * as `Invoice::count() + 1`, which broke two ways: concurrent requests could
 * read the same count, and hard-deleting an invoice shrank the count below
 * the highest number ever issued, so a later create() would reuse a number
 * still in use. InvoiceNumberGenerator replaces that with a single locked
 * counter row.
 */
class InvoiceNumberGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private InvoiceNumberGenerator $generator;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->generator = new InvoiceNumberGenerator();
        $this->user      = User::factory()->create();
    }

    private function makeInvoice(string $invoice_number, array $overrides = []): Invoice
    {
        return Invoice::create(array_merge([
            'unique_id'       => strtoupper(bin2hex(random_bytes(4))),
            'invoice_number'  => $invoice_number,
            'user_id'         => $this->user->id,
            'status'          => 'unpaid',
            'payment_method'  => 'Account Balance',
            'currency_type'   => 'usd',
            'subtotal_amount' => 100.0,
            'discount_amount' => 0.0,
            'total_amount'    => 100.0,
            'credit_amount'   => 0.0,
            'date_issued'     => now(),
        ], $overrides));
    }

    // ─── Basic sequencing ────────────────────────────────────────────────────

    public function test_next_seeds_from_bsm_0001_when_no_invoices_exist(): void
    {
        $number = DB::transaction(fn () => $this->generator->next());

        $this->assertEquals('BSM-0001', $number);
    }

    public function test_next_returns_strictly_increasing_sequential_numbers(): void
    {
        $numbers = [];
        for ($i = 0; $i < 3; $i++) {
            $numbers[] = DB::transaction(fn () => $this->generator->next());
        }

        $this->assertEquals(['BSM-0001', 'BSM-0002', 'BSM-0003'], $numbers);
    }

    public function test_migration_seeds_the_counter_past_pre_existing_invoices(): void
    {
        // Invoices created outside the generator (e.g. via a seeder or an
        // earlier import) must not be reissued once the counter takes over.
        $this->makeInvoice('BSM-0050');

        DB::table('invoice_number_counters')->update([
            'next_number' => (int) DB::table('invoices')
                ->selectRaw("MAX(CAST(SUBSTRING(invoice_number, 5) AS UNSIGNED)) as max_number")
                ->value('max_number') + 1,
        ]);

        $number = DB::transaction(fn () => $this->generator->next());

        $this->assertEquals('BSM-0051', $number);
    }

    // ─── Regression: the original bug ───────────────────────────────────────

    /**
     * Reproduces the exact production scenario: five invoices are created,
     * the earliest three are hard-deleted (the admin "delete invoice"
     * action), which drops Invoice::count() to 2. The old formula would
     * have issued BSM-0003 next, colliding with the still-existing
     * BSM-0004/BSM-0005. The generator must keep counting forward instead.
     */
    public function test_next_number_never_collides_after_invoices_are_deleted(): void
    {
        $invoices = [];
        for ($i = 0; $i < 5; $i++) {
            $number     = DB::transaction(fn () => $this->generator->next());
            $invoices[] = $this->makeInvoice($number);
        }

        $invoices[0]->delete();
        $invoices[1]->delete();
        $invoices[2]->delete();

        $this->assertEquals(2, Invoice::count());

        $next_number = DB::transaction(fn () => $this->generator->next());

        $this->assertEquals('BSM-0006', $next_number);
        $this->assertDatabaseMissing('invoices', ['invoice_number' => $next_number]);
    }

    public function test_next_number_stays_unique_across_many_create_and_delete_cycles(): void
    {
        $seen = [];

        for ($cycle = 0; $cycle < 10; $cycle++) {
            $number = DB::transaction(fn () => $this->generator->next());

            $this->assertArrayNotHasKey($number, $seen, "Invoice number {$number} was issued twice.");
            $seen[$number] = true;

            $invoice = $this->makeInvoice($number);

            // Delete every other invoice right after creating it, so the
            // count keeps drifting further behind the highest number issued.
            if ($cycle % 2 === 0) {
                $invoice->delete();
            }
        }

        $this->assertCount(10, $seen);
    }

    // ─── Self-healing retry ──────────────────────────────────────────────────

    public function test_transact_resyncs_and_retries_when_counter_drifts_behind(): void
    {
        // Simulate a number that ended up in the invoices table without
        // going through the generator (e.g. the legacy portal writing
        // directly to the shared table), leaving the counter pointing at a
        // number that already exists.
        $this->makeInvoice('BSM-0050');
        DB::table('invoice_number_counters')->update(['next_number' => 50]);

        $invoice = $this->generator->transact(function () {
            $number = $this->generator->next();

            return $this->makeInvoice($number);
        });

        $this->assertNotEquals('BSM-0050', $invoice->invoice_number);
        $this->assertEquals(1, Invoice::where('invoice_number', $invoice->invoice_number)->count());
        $this->assertEquals(2, Invoice::count());
    }

    public function test_transact_gives_up_after_max_attempts_if_counter_keeps_colliding(): void
    {
        // Even after resyncing, if a competing process keeps taking the
        // freshly-synced number first, transact() must eventually surface
        // the error instead of retrying forever.
        $this->makeInvoice('BSM-0050');

        $this->expectException(QueryException::class);

        $this->generator->transact(function () {
            // Always attempts to reuse the already-taken number, regardless
            // of what the counter says, to force every attempt to collide.
            return $this->makeInvoice('BSM-0050');
        });
    }

    public function test_transact_does_not_retry_unrelated_unique_constraint_violations(): void
    {
        $existing = $this->makeInvoice('BSM-0010');

        $this->expectException(QueryException::class);

        // Forces a collision on unique_id, a different unique index. This
        // must propagate on the first attempt, not be swallowed as an
        // invoice-number retry case.
        $this->generator->transact(function () use ($existing) {
            $number = $this->generator->next();

            return $this->makeInvoice($number, ['unique_id' => $existing->unique_id]);
        });
    }

    public function test_transact_returns_the_callback_result_on_success(): void
    {
        $invoice = $this->generator->transact(function () {
            $number = $this->generator->next();

            return $this->makeInvoice($number);
        });

        $this->assertInstanceOf(Invoice::class, $invoice);
        $this->assertEquals('BSM-0001', $invoice->invoice_number);
    }
}
