<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('new_content_orders', function (Blueprint $table) {
            $table->text('admin_notes')->nullable()->after('order_notes');
        });
    }

    public function down(): void
    {
        Schema::table('new_content_orders', function (Blueprint $table) {
            $table->dropColumn('admin_notes');
        });
    }
};
