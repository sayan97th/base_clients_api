<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resources', function (Blueprint $table) {
            // Drop the existing foreign key before altering the column
            $table->dropForeign(['organization_id']);

            // Make organization_id nullable (null = visible to all organizations)
            $table->unsignedBigInteger('organization_id')->nullable()->change();

            // Re-add the foreign key with nullOnDelete so deleting an org
            // does not cascade-delete resources shared across all orgs
            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->nullOnDelete();

            // Add status with default 'draft'
            $table->enum('status', ['published', 'draft'])
                ->default('draft')
                ->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('resources', function (Blueprint $table) {
            $table->dropColumn('status');

            $table->dropForeign(['organization_id']);
            $table->unsignedBigInteger('organization_id')->nullable(false)->change();
            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->cascadeOnDelete();
        });
    }
};
