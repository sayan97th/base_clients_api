<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('unique_id', 8)->unique();
            $table->string('invoice_number')->unique();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->uuid('order_id')->nullable();
            $table->enum('status', ['paid', 'void'])->default('paid');
            $table->string('payment_method')->default('Account Balance');
            $table->enum('currency_type', ['usd', 'credits'])->default('usd');
            $table->decimal('subtotal_amount', 10, 2)->default(0);
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->decimal('credit_amount', 10, 2)->default(0);
            $table->timestamp('date_issued')->useCurrent();
            $table->timestamp('date_due')->nullable();
            $table->timestamp('date_paid')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('status');
            $table->index('unique_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
