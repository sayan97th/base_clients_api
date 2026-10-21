<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Team wide formulas of the admin "Link Building Orders" spreadsheet:
 *
 * - kind "column": a calculated column added to the right of the table. Its
 *   formula is written once and evaluated on every row, like a filled down column.
 * - kind "summary": the formula of one cell of the totals row under the table,
 *   e.g. =SUM(AA:AA) under Final Price. target_key is the column it sits under.
 *
 * Values are computed in the browser, only the formulas are stored here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('link_building_order_sheet_formulas', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20);
            $table->string('name', 100)->nullable();
            $table->string('target_key', 64)->nullable();
            $table->text('formula');
            $table->string('format', 20)->default('auto');
            $table->unsignedInteger('position')->default(0);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['kind', 'position']);
            $table->unique(['kind', 'target_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('link_building_order_sheet_formulas');
    }
};
