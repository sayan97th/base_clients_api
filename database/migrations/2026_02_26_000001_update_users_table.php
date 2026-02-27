<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('name');

            $table->string('first_name', 100)->after('id');
            $table->string('last_name', 100)->after('first_name');
            $table->string('business_email')->nullable()->unique()->after('email');
            $table->string('phone', 30)->nullable()->after('password');
            $table->string('job_title')->nullable()->after('phone')->comment('Job title, e.g: Team Manager');
            $table->string('profile_photo_url', 500)->nullable()->after('job_title');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'first_name',
                'last_name',
                'business_email',
                'phone',
                'job_title',
                'profile_photo_url',
            ]);

            $table->string('name')->after('id');
        });
    }
};
