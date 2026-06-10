<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE link_building_orders MODIFY COLUMN status ENUM('new_request','pending','processing','completed','cancelled','payment_pending') NOT NULL DEFAULT 'pending'");
        DB::statement("ALTER TABLE new_content_orders MODIFY COLUMN status ENUM('new_request','pending','processing','completed','cancelled','payment_pending') NOT NULL DEFAULT 'pending'");
        DB::statement("ALTER TABLE content_optimization_orders MODIFY COLUMN status ENUM('new_request','pending','processing','completed','cancelled','payment_pending') NOT NULL DEFAULT 'pending'");
        DB::statement("ALTER TABLE content_brief_orders MODIFY COLUMN status ENUM('new_request','pending','processing','completed','cancelled','payment_pending') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE link_building_orders MODIFY COLUMN status ENUM('pending','processing','completed','cancelled','payment_pending') NOT NULL DEFAULT 'pending'");
        DB::statement("ALTER TABLE new_content_orders MODIFY COLUMN status ENUM('pending','processing','completed','cancelled','payment_pending') NOT NULL DEFAULT 'pending'");
        DB::statement("ALTER TABLE content_optimization_orders MODIFY COLUMN status ENUM('pending','processing','completed','cancelled','payment_pending') NOT NULL DEFAULT 'pending'");
        DB::statement("ALTER TABLE content_brief_orders MODIFY COLUMN status ENUM('pending','processing','completed','cancelled','payment_pending') NOT NULL DEFAULT 'pending'");
    }
};
