<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('premium_mentions_appointments', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();

            $table->enum('status', ['pending', 'confirmed', 'cancelled', 'completed'])
                ->default('pending')
                ->after('invitee_uri');

            $table->text('notes')->nullable()->after('scheduled_at');
            $table->text('admin_notes')->nullable()->after('notes');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('premium_mentions_appointments', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            $table->dropColumn(['status', 'notes', 'admin_notes', 'deleted_at']);
        });
    }
};
