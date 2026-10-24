<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Edit history of the Link Building Orders table, the portal version of the
 * "Version history" and "Show edit history" of a Google Sheet. Every create,
 * update and delete of a placement leaves one row here, so a value that was
 * pasted over or deleted by mistake can always be looked up and restored.
 *
 * `batch_id` groups the revisions written by one action (a single cell edit, a
 * paste over many rows, an import), so the whole action can be restored at once.
 * `cell_changes` holds {column: {old, new}} for updates, `snapshot` the full row for
 * creates and deletes. `placement_id` has no foreign key on purpose: the history
 * of a deleted row must outlive it, that is what makes it restorable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('link_building_order_placement_revisions', function (Blueprint $table) {
            $table->id();
            $table->string('batch_id', 64);
            $table->uuid('placement_id');
            $table->string('order_id', 50)->nullable();
            $table->string('event', 20);
            $table->string('source', 30);
            $table->json('cell_changes')->nullable();
            $table->json('snapshot')->nullable();
            $table->string('restored_batch_id', 64)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index('batch_id');
            $table->index(['placement_id', 'id']);
            $table->index('order_id');
            $table->index('restored_batch_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('link_building_order_placement_revisions');
    }
};
