<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->string('resource_type', 50)->nullable()->after('link');
            $table->string('resource_id', 100)->nullable()->after('resource_type');
            $table->json('metadata')->nullable()->after('resource_id');

            $table->index(['resource_type', 'resource_id']);
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['resource_type', 'resource_id']);
            $table->dropColumn(['resource_type', 'resource_id', 'metadata']);
        });
    }
};
