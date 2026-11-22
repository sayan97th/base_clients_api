<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing history for SEO subscriptions: one row per billing period.
 *
 * The unique (subscription, period_start) pair is the idempotency guard that
 * makes it impossible to bill the same month twice, no matter how many times
 * the scheduler or an admin triggers a charge. Retries of a failed period
 * update the same row and bump attempt_count.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_subscription_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seo_package_subscription_id')->constrained('seo_package_subscriptions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('invoice_id')->nullable();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('amount', 10, 2);
            $table->string('status', 20)->default('pending');
            $table->string('trigger', 20)->default('automatic');
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->string('payment_intent_id')->nullable();
            $table->uuid('payment_profile_id')->nullable();
            $table->string('card_label')->nullable();
            $table->string('failure_code')->nullable();
            $table->text('failure_message')->nullable();
            $table->foreignId('charged_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->foreign('invoice_id')->references('id')->on('invoices')->nullOnDelete();
            $table->unique(['seo_package_subscription_id', 'period_start'], 'seo_sub_charges_period_unique');
            $table->index(['status', 'next_retry_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_subscription_charges');
    }
};
