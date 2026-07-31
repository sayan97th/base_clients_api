<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Invoice numbers were previously derived from `Invoice::count() + 1`, which
 * is neither race-safe (two concurrent requests can read the same count) nor
 * gap-safe (deleting an invoice shrinks the count below the highest number
 * already issued). Both produced duplicate "BSM-XXXX" numbers that violated
 * invoices.invoices_invoice_number_unique. This table backs a single locked
 * counter row so the next number is reserved atomically, see
 * App\Services\InvoiceNumberGenerator.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_number_counters', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('next_number');
            $table->timestamps();
        });

        $max_issued_number = (int) DB::table('invoices')
            ->selectRaw("MAX(CAST(SUBSTRING(invoice_number, 5) AS UNSIGNED)) as max_number")
            ->value('max_number');

        DB::table('invoice_number_counters')->insert([
            'next_number' => $max_issued_number + 1,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_number_counters');
    }
};
