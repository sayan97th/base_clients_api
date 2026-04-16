<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sme_authored_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('tier_key', 100)->unique();
            $table->string('label');
            $table->text('description');
            $table->unsignedInteger('price');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sme_authored_tiers');
    }
};
