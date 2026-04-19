<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('credit_amount');
        });

        Schema::table('invoice_line_items', function (Blueprint $table) {
            $table->text('description')->nullable()->after('item_name');
            $table->decimal('discount_percent', 5, 2)->default(0)->after('item_total');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('notes');
        });

        Schema::table('invoice_line_items', function (Blueprint $table) {
            $table->dropColumn(['description', 'discount_percent']);
        });
    }
};
