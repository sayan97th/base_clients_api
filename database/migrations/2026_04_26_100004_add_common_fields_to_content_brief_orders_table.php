<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_brief_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('content_brief_orders', 'order_title')) {
                $table->string('order_title')->nullable()->after('user_id');
            }
            if (!Schema::hasColumn('content_brief_orders', 'subtotal_before_discount')) {
                $table->decimal('subtotal_before_discount', 10, 2)->nullable()->after('order_notes');
            }
            if (!Schema::hasColumn('content_brief_orders', 'session_id')) {
                $table->string('session_id')->nullable()->index()->after('payment_intent_id');
            }
            if (!Schema::hasColumn('content_brief_orders', 'session_title')) {
                $table->string('session_title')->nullable()->after('session_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('content_brief_orders', function (Blueprint $table) {
            if (Schema::hasColumn('content_brief_orders', 'session_id')) {
                $table->dropIndex(['session_id']);
            }
            $table->dropColumn(array_filter(
                ['order_title', 'subtotal_before_discount', 'session_id', 'session_title'],
                fn($col) => Schema::hasColumn('content_brief_orders', $col)
            ));
        });
    }
};
