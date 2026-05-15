<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_team_user', function (Blueprint $table) {
            $table->char('admin_team_id', 36);
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 30)->default('member');
            $table->timestamp('joined_at')->useCurrent();

            $table->primary(['admin_team_id', 'user_id']);
            $table->foreign('admin_team_id')->references('id')->on('admin_teams')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_team_user');
    }
};
