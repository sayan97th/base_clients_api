<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Audit log of every off-session charge against a saved card — both
        // automatic (autopay) and admin-initiated ("Charge card on file").
        // A row is written BEFORE Stripe is called, so a crash mid-charge always
        // leaves a `processing` row behind that the reconciler can resolve
        // against Stripe instead of silently losing (or repeating) a charge.
        Schema::create('invoice_charge_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('payment_profile_id')->nullable()->constrained('payment_profiles')->nullOnDelete();
            $table->string('source', 20);
            $table->unsignedSmallInteger('attempt_number')->default(1);
            $table->string('status', 20);
            $table->decimal('amount', 10, 2);
            $table->string('card_brand', 50)->nullable();
            $table->char('card_last_four', 4)->nullable();
            $table->string('stripe_payment_intent_id')->nullable();
            $table->string('idempotency_key')->unique();
            $table->string('failure_code')->nullable();
            $table->text('failure_message')->nullable();
            $table->boolean('is_retryable')->default(false);
            $table->timestamp('next_retry_at')->nullable();
            $table->foreignId('initiated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('initiated_by_name')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['invoice_id', 'source']);
            $table->index(['status', 'created_at']);
            $table->index('stripe_payment_intent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_charge_attempts');
    }
};
