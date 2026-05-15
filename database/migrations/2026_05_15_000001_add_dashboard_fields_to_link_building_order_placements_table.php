<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('link_building_order_placements', function (Blueprint $table) {
            // Make order_item_id nullable so standalone dashboard rows can be created
            // without being tied to a purchase order item.
            $table->dropForeign(['order_item_id']);
            $table->uuid('order_item_id')->nullable()->change();
            $table->foreign('order_item_id')
                ->references('id')
                ->on('link_building_order_items')
                ->nullOnDelete();

            // Ensure placement-system columns exist (they may have been added
            // by an earlier migration or may still be pending).
            if (! Schema::hasColumn('link_building_order_placements', 'live_link')) {
                $table->string('live_link', 2000)->nullable()->after('exact_match');
            }
            if (! Schema::hasColumn('link_building_order_placements', 'dr')) {
                $table->unsignedSmallInteger('dr')->nullable()->after('live_link');
            }
            if (! Schema::hasColumn('link_building_order_placements', 'completed_date')) {
                $table->dateTime('completed_date')->nullable()->after('dr');
            }

            // ── Dashboard fields (mirror of backlink_orders schema) ──────────
            $table->string('order_id', 50)->nullable()->unique()->after('id');
            $table->string('status')->nullable()->default('New Request')->after('order_id');
            $table->string('team_specific_link_id', 50)->nullable()->after('status');
            $table->string('link_type')->nullable()->after('team_specific_link_id');
            $table->string('client')->nullable()->after('link_type');
            $table->text('notes')->nullable()->after('exact_match');
            $table->string('request_date', 20)->nullable()->after('notes');
            $table->string('estimated_delivery_date', 20)->nullable()->after('request_date');
            $table->string('estimated_turnaround_days', 20)->nullable()->after('estimated_delivery_date');
            $table->unsignedBigInteger('link_builder_user_id')->nullable()->after('estimated_turnaround_days');
            $table->foreign('link_builder_user_id')->references('id')->on('users')->nullOnDelete();
            $table->string('link_builder')->nullable()->after('link_builder_user_id');
            $table->string('pen_name')->nullable()->after('link_builder');
            $table->string('partnership', 2000)->nullable()->after('pen_name');
            $table->string('article_title', 500)->nullable()->after('partnership');
            $table->string('article', 2000)->nullable()->after('article_title');
            $table->string('live_link_date', 20)->nullable()->after('live_link');
            $table->string('dr_lbs', 20)->nullable()->after('live_link_date');
            $table->string('posting_fee_lbs', 50)->nullable()->after('dr_lbs');
            $table->string('current_traffic', 50)->nullable()->after('posting_fee_lbs');
            $table->string('dr_formula', 50)->nullable()->after('current_traffic');
            $table->string('current_poc')->nullable()->after('dr_formula');
            $table->string('current_price', 100)->nullable()->after('current_poc');
            $table->string('lb_tl_approval')->nullable()->after('current_price');
            $table->string('approval_date', 20)->nullable()->after('lb_tl_approval');
            $table->string('final_price', 100)->nullable()->after('approval_date');
        });
    }

    public function down(): void
    {
        Schema::table('link_building_order_placements', function (Blueprint $table) {
            $table->dropForeign(['link_builder_user_id']);
            $table->dropForeign(['order_item_id']);

            $table->dropColumn([
                'order_id', 'status', 'team_specific_link_id', 'link_type', 'client',
                'notes', 'request_date', 'estimated_delivery_date', 'estimated_turnaround_days',
                'link_builder_user_id', 'link_builder', 'pen_name', 'partnership',
                'article_title', 'article', 'live_link_date', 'dr_lbs', 'posting_fee_lbs',
                'current_traffic', 'dr_formula', 'current_poc', 'current_price',
                'lb_tl_approval', 'approval_date', 'final_price',
            ]);

            $table->dropForeign(['order_item_id']);
            $table->uuid('order_item_id')->nullable(false)->change();
            $table->foreign('order_item_id')
                ->references('id')
                ->on('link_building_order_items')
                ->cascadeOnDelete();
        });
    }
};
