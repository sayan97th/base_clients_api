<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const NEW_STATUSES = ['unpaid', 'paid', 'overdue', 'refund', 'partial_refund', 'dispute', 'void'];
    private const OLD_STATUSES = ['unpaid', 'paid', 'overdue', 'refund', 'void'];

    public function up(): void
    {
        // Expand the invoice status enum to mirror the Stripe-aligned transaction
        // types. 'partial_refund' is set automatically when an admin issues a
        // partial refund; 'dispute' is available for chargeback/dispute tracking.
        $this->setStatusEnum(self::NEW_STATUSES);
    }

    public function down(): void
    {
        // Collapse the new statuses into the closest legacy value before shrinking
        // the enum so existing rows remain valid.
        DB::statement("UPDATE invoices SET status = 'refund' WHERE status IN ('partial_refund', 'dispute')");

        $this->setStatusEnum(self::OLD_STATUSES);
    }

    /**
     * Rewrite the invoices.status enum. MySQL expands the column in place via
     * MODIFY COLUMN; SQLite (used in tests) rebuilds the CHECK constraint through
     * Laravel's native column change.
     */
    private function setStatusEnum(array $statuses): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('invoices', function (Blueprint $table) use ($statuses) {
                $table->enum('status', $statuses)->default('unpaid')->change();
            });

            return;
        }

        $values = implode(', ', array_map(fn ($status) => "'{$status}'", $statuses));
        DB::statement("ALTER TABLE invoices MODIFY COLUMN status ENUM({$values}) NOT NULL DEFAULT 'unpaid'");
    }
};
