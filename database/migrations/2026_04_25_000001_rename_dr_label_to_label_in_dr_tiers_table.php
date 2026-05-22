<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('dr_tiers', 'dr_label')) {
            Schema::table('dr_tiers', function (Blueprint $table) {
                $table->renameColumn('dr_label', 'label');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('dr_tiers', 'label')) {
            Schema::table('dr_tiers', function (Blueprint $table) {
                $table->renameColumn('label', 'dr_label');
            });
        }
    }
};
