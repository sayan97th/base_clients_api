<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resource_id')->constrained('resources')->cascadeOnDelete();
            $table->string('name');
            $table->string('file_type', 10);
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('file_path', 500);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_files');
    }
};
