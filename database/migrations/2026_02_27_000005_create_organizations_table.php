<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('slug', 150)->unique();
            $table->text('description')->nullable();
            $table->string('website', 255)->nullable();
            $table->string('contact_email', 255)->nullable();
            $table->string('contact_phone', 30)->nullable();
            $table->string('contact_link', 500)->nullable();
            $table->string('logo_light', 500)->nullable()->comment('Logo for light backgrounds');
            $table->string('logo_dark', 500)->nullable()->comment('Logo for dark backgrounds');
            $table->string('icon_light', 500)->nullable()->comment('Icon for light backgrounds');
            $table->string('icon_dark', 500)->nullable()->comment('Icon for dark backgrounds');
            $table->string('mobile_app_icon', 500)->nullable();
            $table->string('primary_color', 7)->nullable()->comment('Hex color e.g. #FF5733');
            $table->string('accent_color', 7)->nullable()->comment('Hex color');
            $table->string('timezone', 50)->default('America/Boise');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
