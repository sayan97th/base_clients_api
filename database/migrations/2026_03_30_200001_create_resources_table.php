<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('category', [
                'pdf',
                'spreadsheet',
                'document',
                'presentation',
                'image',
                'blog_post',
                'other',
            ]);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resources');
    }
};
