<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Update any existing rows that use removed statuses before changing the enum
        DB::table('backlink_orders')
            ->where('status', 'In Progress')
            ->update(['status' => 'Reviewing']);

        Schema::table('backlink_orders', function (Blueprint $table) {
            $table->enum('status', [
                'New Request',
                'Reviewing',
                'Ordered',
                'Pending',
                'Live',
                'Quality Control',
                'Cancelled',
            ])->default('New Request')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('backlink_orders')
            ->whereIn('status', ['New Request', 'Reviewing', 'Ordered', 'Quality Control'])
            ->update(['status' => 'Pending']);

        Schema::table('backlink_orders', function (Blueprint $table) {
            $table->enum('status', ['Live', 'Pending', 'In Progress', 'Cancelled'])
                ->default('Pending')
                ->change();
        });
    }
};
