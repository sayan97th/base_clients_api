<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_report_rows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_report_table_id');
            $table->string('order_number', 50);
            $table->string('link_type', 100);
            $table->string('keyword', 255);
            $table->string('landing_page', 2048);
            $table->boolean('exact_match')->default(true);
            $table->date('request_date');
            $table->enum('status', ['pending', 'live', 'rejected'])->default('pending');
            $table->string('live_link', 2048)->nullable();
            $table->date('live_link_date')->nullable();
            $table->unsignedTinyInteger('dr')->nullable();
            $table->timestamps();

            $table->foreign('order_report_table_id')->references('id')->on('order_report_tables')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_report_rows');
    }
};
