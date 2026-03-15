<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 32)->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->enum('discount_type', ['percentage', 'fixed_amount']);
            $table->decimal('discount_value', 10, 2);
            $table->enum('applies_to', ['all', 'specific_product', 'minimum_purchase']);
            $table->string('dr_tier_id')->nullable();
            $table->decimal('minimum_purchase_amount', 10, 2)->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at');
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('usage_per_user')->nullable();
            $table->unsignedInteger('times_used')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('dr_tier_id')->references('id')->on('dr_tiers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
