<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('refund_amount', 10, 2)->nullable()->default(null)->after('credit_amount');
            $table->timestamp('refunded_at')->nullable()->default(null)->after('date_paid');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['refund_amount', 'refunded_at']);
        });
    }
};
