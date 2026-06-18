<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type');           // purchase, credit_payment, hybrid_payment, failed_purchase
            $table->string('status');         // success, failed
            $table->decimal('amount', 10, 2)->default(0);
            $table->string('payment_method'); // credit_card, account_credits, hybrid
            $table->string('payment_intent_id')->nullable();
            $table->string('session_id')->nullable()->index();
            $table->string('session_title')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();  // nullable — may fail before order is created
            $table->string('invoice_id')->nullable();            // nullable — invoice created after order
            $table->text('description')->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
