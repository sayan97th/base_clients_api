<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('news_placements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('domain');
            $table->string('dr')->nullable();
            $table->string('traffic')->nullable();
            $table->string('category')->nullable();
            $table->string('price')->nullable();
            $table->string('types_of_content')->nullable();
            $table->string('do_follow_no_follow')->nullable();
            $table->string('indexable')->nullable();
            $table->string('well_known_site')->nullable();
            $table->string('links_allowed')->nullable();
            $table->text('additional_notes')->nullable();
            $table->string('price_1')->nullable();
            $table->string('poc_1')->nullable();
            $table->string('price_2')->nullable();
            $table->string('poc_2')->nullable();
            $table->string('tier')->nullable();
            $table->string('pbn_check')->nullable();
            $table->string('used_domain')->nullable();
            $table->string('within_budget')->nullable();
            $table->string('ref_domains')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_placements');
    }
};
