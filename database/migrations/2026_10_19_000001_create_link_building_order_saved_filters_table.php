<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Named filter presets for the admin "Link Building Orders" table. Each row holds
 * a snapshot of the toolbar filters, column filters, sort rules and hidden columns,
 * so an admin can re-apply the same view in one click instead of rebuilding it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('link_building_order_saved_filters', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->json('filters');
            $table->boolean('is_shared')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'name']);
            $table->index('is_shared');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('link_building_order_saved_filters');
    }
};
