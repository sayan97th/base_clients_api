<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::select("SHOW COLUMNS FROM `dr_tiers` LIKE 'dr_label'");
        if (!empty($exists)) {
            Schema::table('dr_tiers', function (Blueprint $table) {
                $table->renameColumn('dr_label', 'label');
            });
        }
    }

    public function down(): void
    {
        $exists = DB::select("SHOW COLUMNS FROM `dr_tiers` LIKE 'label'");
        if (!empty($exists)) {
            Schema::table('dr_tiers', function (Blueprint $table) {
                $table->renameColumn('label', 'dr_label');
            });
        }
    }
};
