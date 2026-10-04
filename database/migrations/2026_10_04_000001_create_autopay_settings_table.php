<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per client. Autopay is strictly opt-in: a row only exists once
        // the client has enabled it at least once, and charges only ever run
        // while `is_enabled` is true. The consent fields keep an auditable record
        // of who authorized the recurring charges, when, and from where.
        Schema::create('autopay_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('payment_profile_id')->nullable()->constrained('payment_profiles')->nullOnDelete();
            $table->boolean('is_enabled')->default(false);
            // Optional per-charge safety cap. Invoices above this amount are never
            // charged automatically — they require a manual payment instead.
            $table->decimal('max_amount', 10, 2)->nullable();
            $table->text('consent_text')->nullable();
            $table->string('consent_ip', 45)->nullable();
            $table->string('consent_user_agent', 512)->nullable();
            $table->timestamp('enabled_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->string('disabled_by_type', 20)->nullable();
            $table->string('disabled_by_name')->nullable();
            $table->string('disabled_reason')->nullable();
            $table->timestamps();

            $table->index('is_enabled');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('autopay_settings');
    }
};
