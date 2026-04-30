<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_package_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('seo_package_id');
            $table->foreign('seo_package_id')->references('id')->on('seo_packages')->cascadeOnDelete();
            $table->enum('status', ['active', 'cancelled', 'expired'])->default('active');
            $table->date('starts_at');
            $table->date('ends_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('seo_package_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_package_subscriptions');
    }
};
