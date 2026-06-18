<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->enum('type', [
                'payment',
                'post',
                'system',
                'order',
                'user_registration',
                'order_comment',
                'invoice',
            ])->notNull()->change();
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->enum('type', [
                'payment',
                'post',
                'system',
                'order',
                'user_registration',
                'order_comment',
            ])->notNull()->change();
        });
    }
};
