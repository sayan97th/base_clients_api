<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Read only copy of a Link Building order exactly as the client submitted it.
 * The order's placements are edited afterwards on the Link Building Orders table,
 * and those edits overwrite the live rows everywhere they are shown, so this copy
 * is what the team checks when they need the original request back.
 *
 * `type` is "placed" for the checkout itself (whatever the payment method) and
 * "details_submitted" each time the keywords and landing pages of a deferred
 * order are filled in later. `is_backfilled` marks copies taken from the data an
 * existing order had when this table was introduced, not at checkout. Rows are
 * never updated, see LinkBuildingOrderSnapshot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('link_building_order_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_id');
            $table->string('type', 30);
            $table->string('payment_method', 100)->nullable();
            $table->json('payload');
            $table->boolean('is_backfilled')->default(false);
            $table->foreignId('captured_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->foreign('order_id')->references('id')->on('link_building_orders')->cascadeOnDelete();
            $table->index(['order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('link_building_order_snapshots');
    }
};
