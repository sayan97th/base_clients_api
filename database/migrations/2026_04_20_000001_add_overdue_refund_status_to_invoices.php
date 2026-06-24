<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL uses MODIFY COLUMN to expand ENUMs; SQLite does not enforce ENUMs
        // so this DDL is skipped in the test environment.
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE invoices MODIFY COLUMN status ENUM('unpaid', 'paid', 'overdue', 'refund', 'void') NOT NULL DEFAULT 'unpaid'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE invoices MODIFY COLUMN status ENUM('unpaid', 'paid', 'void') NOT NULL DEFAULT 'unpaid'");
        }
    }
};
