<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backlink_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('order_id', 50)->unique();
            $table->string('team_specific_link_id', 50)->nullable();
            $table->string('link_type', 100)->nullable();
            $table->string('client', 255)->nullable();
            $table->string('keyword', 500)->nullable();
            $table->string('landing_page', 2000)->nullable();
            $table->enum('exact_match', ['Yes', 'No'])->default('No');
            $table->text('notes')->nullable();
            $table->string('request_date', 20)->nullable();            // stored as MM/DD/YYYY string
            $table->string('estimated_delivery_date', 20)->nullable(); // stored as MM/DD/YYYY string
            $table->unsignedSmallInteger('estimated_turnaround_days')->nullable()->default(30);
            $table->foreignId('link_builder_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('link_builder', 255)->nullable();           // display name string
            $table->string('pen_name', 255)->nullable();
            $table->string('partnership', 2000)->nullable();           // domain or URL
            $table->string('article_title', 500)->nullable();
            $table->string('article', 2000)->nullable();               // Google Docs URL
            $table->enum('status', ['Live', 'Pending', 'In Progress', 'Cancelled'])->default('Pending');
            $table->string('live_link', 2000)->nullable();
            $table->string('live_link_date', 20)->nullable();
            $table->string('dr_lbs', 20)->nullable();
            $table->string('posting_fee_lbs', 50)->nullable();
            $table->string('current_traffic', 50)->nullable();
            $table->string('dr_formula', 50)->nullable();
            $table->string('current_poc', 255)->nullable();
            $table->string('current_price', 100)->nullable();
            $table->string('lb_tl_approval', 255)->nullable();
            $table->string('approval_date', 20)->nullable();
            $table->string('final_price', 100)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backlink_orders');
    }
};
