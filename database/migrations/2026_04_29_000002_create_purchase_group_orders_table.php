<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_group_orders', function (Blueprint $table) {
            $table->id();
            $table->char('purchase_group_id', 36);
            $table->char('order_id', 36);
            $table->enum('product_type', [
                'link_building',
                'new_content',
                'content_optimization',
                'content_brief',
            ]);
            $table->decimal('total_amount', 10, 2);

            $table->foreign('purchase_group_id')
                  ->references('purchase_group_id')
                  ->on('purchase_groups')
                  ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_group_orders');
    }
};
