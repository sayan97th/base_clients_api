<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Expand the invoice status enum to mirror the Stripe-aligned transaction
        // types. 'partial_refund' is set automatically when an admin issues a
        // partial refund; 'dispute' is available for chargeback/dispute tracking.
        // MySQL uses MODIFY COLUMN to expand ENUMs; SQLite does not enforce ENUMs
        // so this DDL is skipped in the test environment.
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE invoices MODIFY COLUMN status ENUM('unpaid', 'paid', 'overdue', 'refund', 'partial_refund', 'dispute', 'void') NOT NULL DEFAULT 'unpaid'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            // Collapse any new statuses back into the closest legacy value before
            // shrinking the enum so existing rows remain valid.
            DB::statement("UPDATE invoices SET status = 'refund' WHERE status IN ('partial_refund', 'dispute')");
            DB::statement("ALTER TABLE invoices MODIFY COLUMN status ENUM('unpaid', 'paid', 'overdue', 'refund', 'void') NOT NULL DEFAULT 'unpaid'");
        }
    }
};
