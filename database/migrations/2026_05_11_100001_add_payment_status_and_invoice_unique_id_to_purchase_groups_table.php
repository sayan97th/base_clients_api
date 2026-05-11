<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_groups', function (Blueprint $table) {
            $table->string('payment_status', 20)->default('paid')->after('total_amount');
            $table->string('invoice_unique_id', 50)->nullable()->after('payment_status');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_groups', function (Blueprint $table) {
            $table->dropColumn(['payment_status', 'invoice_unique_id']);
        });
    }
};
