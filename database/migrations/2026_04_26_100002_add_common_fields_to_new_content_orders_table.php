<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('new_content_orders', function (Blueprint $table) {
            $table->string('order_title')->nullable()->after('user_id');
            $table->decimal('subtotal_before_discount', 10, 2)->nullable()->after('order_notes');
            $table->string('session_id')->nullable()->index()->after('payment_intent_id');
            $table->string('session_title')->nullable()->after('session_id');
        });
    }

    public function down(): void
    {
        Schema::table('new_content_orders', function (Blueprint $table) {
            $table->dropIndex(['session_id']);
            $table->dropColumn(['order_title', 'subtotal_before_discount', 'session_id', 'session_title']);
        });
    }
};
