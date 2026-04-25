<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_coupon_discounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->string('code');
            $table->string('name')->nullable();
            $table->string('discount_type');
            $table->decimal('discount_value', 10, 2);
            $table->decimal('discount_amount', 10, 2);
            $table->timestamps();

            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_coupon_discounts');
    }
};
