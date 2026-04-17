<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sme_appointments', function (Blueprint $table) {
            $table->string('service_type', 50)->after('user_id')->default('authored');
        });
    }

    public function down(): void
    {
        Schema::table('sme_appointments', function (Blueprint $table) {
            $table->dropColumn('service_type');
        });
    }
};
