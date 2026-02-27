<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_role', function (Blueprint $table) {
            $table->dropPrimary(['user_id', 'role_id']);
        });

        Schema::table('user_role', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('role_id')->constrained()->cascadeOnDelete();
            $table->unique(['user_id', 'role_id', 'organization_id'], 'user_role_organization_unique');
        });
    }

    public function down(): void
    {
        Schema::table('user_role', function (Blueprint $table) {
            $table->dropUnique('user_role_organization_unique');
            $table->dropForeign(['organization_id']);
            $table->dropColumn('organization_id');
        });

        Schema::table('user_role', function (Blueprint $table) {
            $table->primary(['user_id', 'role_id']);
        });
    }
};
