<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sme_appointments', function (Blueprint $table) {
            $table->string('status', 20)->default('pending')->after('service_type');
            $table->text('notes')->nullable()->after('status');
            $table->text('admin_notes')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('sme_appointments', function (Blueprint $table) {
            $table->dropColumn(['status', 'notes', 'admin_notes']);
        });
    }
};
