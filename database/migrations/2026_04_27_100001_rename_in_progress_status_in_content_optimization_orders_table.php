<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('content_optimization_orders')
            ->where('status', 'in_progress')
            ->update(['status' => 'processing']);
    }

    public function down(): void
    {
        DB::table('content_optimization_orders')
            ->where('status', 'processing')
            ->update(['status' => 'in_progress']);
    }
};
