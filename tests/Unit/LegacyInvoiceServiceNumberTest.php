<?php

namespace Tests\Unit;

use App\Models\DrTier;
use App\Models\Invoice;
use App\Models\LinkBuildingOrder;
use App\Models\LinkBuildingOrderItem;
use App\Models\User;
use App\Services\InvoiceNumberGenerator;
use App\Services\LegacyInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * LegacyInvoiceService::generate() is the third of four call sites that used
 * to compute invoice numbers as `Invoice::count() + 1` (see
 * InvoiceNumberGeneratorTest for the underlying generator coverage). This
 * confirms the legacy-import path was wired to the same shared, gap-safe
 * counter as the admin "Create Invoice" flow.
 */
class LegacyInvoiceServiceNumberTest extends TestCase
{
    use RefreshDatabase;

    private LegacyInvoiceService $service;
    private DrTier $dr_tier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new LegacyInvoiceService(new InvoiceNumberGenerator());

        $this->dr_tier = DrTier::create([
            'id'             => 'dr30',
            'label'          => 'DR 30+',
            'traffic_range'  => '1k-10k',
            'word_count'     => 800,
            'price_per_link' => 100.00,
        ]);
    }

    private function makeLegacyOrder(): LinkBuildingOrder
    {
        $user = User::factory()->create();

        $order = LinkBuildingOrder::create([
            'user_id'                  => $user->id,
            'order_title'              => 'Legacy Order',
            'subtotal_before_discount' => 200.0,
            'total_amount'             => 200.0,
            'status'                   => 'completed',
            'is_legacy_import'         => true,
        ]);

        LinkBuildingOrderItem::create([
            'order_id'   => $order->id,
            'dr_tier_id' => $this->dr_tier->id,
            'quantity'   => 1,
            'unit_price' => 200.0,
            'subtotal'   => 200.0,
        ]);

        return $order;
    }

    public function test_generate_produces_sequential_unique_invoice_numbers(): void
    {
        $numbers = [];
        for ($i = 0; $i < 3; $i++) {
            $numbers[] = $this->service->generate($this->makeLegacyOrder())->invoice_number;
        }

        $this->assertEquals(['BSM-0001', 'BSM-0002', 'BSM-0003'], $numbers);
    }

    public function test_generate_does_not_collide_after_an_earlier_invoice_is_deleted(): void
    {
        $invoices = [];
        for ($i = 0; $i < 4; $i++) {
            $invoices[] = $this->service->generate($this->makeLegacyOrder());
        }

        // Delete an early invoice while later ones survive — the same
        // pattern that made `Invoice::count() + 1` reissue an in-use number.
        $invoices[0]->delete();
        $this->assertEquals(3, Invoice::count());

        $new_invoice = $this->service->generate($this->makeLegacyOrder());

        $this->assertEquals('BSM-0005', $new_invoice->invoice_number);
        $this->assertDatabaseHas('invoices', ['invoice_number' => $new_invoice->invoice_number]);
    }

    /**
     * Legacy-import generation and the admin "Create Invoice" flow draw
     * numbers from the same counter table. A legacy order processed after a
     * manually-created invoice must continue the sequence, not restart it
     * and collide with numbers the admin flow already issued.
     */
    public function test_generate_shares_the_same_counter_as_other_invoice_number_consumers(): void
    {
        $shared_generator = new InvoiceNumberGenerator();

        $manual_number = DB::transaction(fn () => $shared_generator->next());
        $this->assertEquals('BSM-0001', $manual_number);

        $legacy_invoice = $this->service->generate($this->makeLegacyOrder());

        $this->assertEquals('BSM-0002', $legacy_invoice->invoice_number);
    }
}
