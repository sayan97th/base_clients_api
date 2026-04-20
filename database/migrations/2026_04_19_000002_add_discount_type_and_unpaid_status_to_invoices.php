<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add discount_type column
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('discount_type')->nullable()->after('discount_amount');
        });

        // Expand status enum to include 'unpaid'
        DB::statement("ALTER TABLE invoices MODIFY COLUMN status ENUM('unpaid', 'paid', 'void') NOT NULL DEFAULT 'unpaid'");
    }

    public function down(): void
    {
        // Revert status enum (rows with 'unpaid' must be handled before rollback)
        DB::statement("ALTER TABLE invoices MODIFY COLUMN status ENUM('paid', 'void') NOT NULL DEFAULT 'paid'");

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('discount_type');
        });
    }
};
