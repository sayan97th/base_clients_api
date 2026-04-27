<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_line_items', function (Blueprint $table) {
            $table->uuid('order_id')->nullable()->after('invoice_id');
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_line_items', function (Blueprint $table) {
            $table->dropIndex(['order_id']);
            $table->dropColumn('order_id');
        });
    }
};
