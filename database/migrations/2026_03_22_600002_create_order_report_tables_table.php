<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_report_tables', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_report_id');
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->foreign('order_report_id')->references('id')->on('order_reports')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_report_tables');
    }
};
