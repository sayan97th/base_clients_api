<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('profile_photo_path', 500)
                ->nullable()
                ->after('profile_photo_url')
                ->comment('Relative storage path for the profile photo');
        });

        DB::table('users')
            ->whereNotNull('profile_photo_url')
            ->eachById(function ($user) {
                $parsed_path = parse_url($user->profile_photo_url, PHP_URL_PATH);
                $relative_path = $parsed_path ? ltrim(str_replace('/storage/', '', $parsed_path), '/') : null;

                DB::table('users')
                    ->where('id', $user->id)
                    ->update(['profile_photo_path' => $relative_path]);
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('profile_photo_path');
        });
    }
};
