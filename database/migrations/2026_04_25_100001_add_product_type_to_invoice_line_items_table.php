<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_line_items', function (Blueprint $table) {
            $table->string('product_type')->nullable()->after('item_name');
            $table->index('product_type');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_line_items', function (Blueprint $table) {
            $table->dropIndex(['product_type']);
            $table->dropColumn('product_type');
        });
    }
};
