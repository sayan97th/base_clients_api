<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The last view each user had on the admin "Link Building Orders" table, saved
 * automatically as filters change, so the table opens exactly as they left it.
 * One row per user. `applied_saved_filter_id` remembers which saved filter (if
 * any) the view came from, and is cleared when that saved filter is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('link_building_order_filter_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('filters');
            $table->uuid('applied_saved_filter_id')->nullable();
            $table->timestamps();

            // Named explicitly, the generated name exceeds MySQL's 64 character limit.
            $table->foreign('applied_saved_filter_id', 'lbo_filter_prefs_applied_saved_filter_fk')
                ->references('id')
                ->on('link_building_order_saved_filters')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('link_building_order_filter_preferences');
    }
};
