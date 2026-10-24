<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One summary row per action of the Link Building Orders edit history (a paste,
 * an import, a restore), kept up to date by LinkBuildingOrderHistoryRecorder as
 * the revisions of the action are written. The Version history list reads only
 * this table, so it stays fast however many revisions the table collects: an
 * import over 30,000 rows is one row here instead of a GROUP BY over 30,000
 * revisions on every page.
 *
 * Also replaces the single column revision indexes with the composite ones the
 * history queries filter and page by.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('link_building_order_history_batches', function (Blueprint $table) {
            $table->id();
            $table->string('batch_id', 64)->unique();
            $table->string('source', 30);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('restored_batch_id', 64)->nullable();
            $table->unsignedInteger('revisions_count')->default(0);
            $table->unsignedInteger('rows_count')->default(0);
            $table->unsignedInteger('cells_count')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('deleted_count')->default(0);
            $table->json('order_ids_preview')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_change_at')->nullable();
            $table->timestamp('restored_at')->nullable();
            $table->foreignId('restored_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->index(['source', 'id']);
            $table->index(['user_id', 'id']);
            $table->index('last_change_at');
        });

        Schema::table('link_building_order_placement_revisions', function (Blueprint $table) {
            $table->dropIndex(['batch_id']);
            $table->dropIndex(['order_id']);
            $table->index(['batch_id', 'id']);
            $table->index(['order_id', 'batch_id']);
        });

        $this->backfillBatches();
    }

    public function down(): void
    {
        Schema::table('link_building_order_placement_revisions', function (Blueprint $table) {
            $table->dropIndex(['batch_id', 'id']);
            $table->dropIndex(['order_id', 'batch_id']);
            $table->index('batch_id');
            $table->index('order_id');
        });

        Schema::dropIfExists('link_building_order_history_batches');
    }

    /** Summaries of the revisions recorded before this table existed, oldest action first. */
    private function backfillBatches(): void
    {
        $batch_ids = DB::table('link_building_order_placement_revisions')
            ->groupBy('batch_id')
            ->orderByRaw('MIN(id)')
            ->pluck('batch_id');

        foreach ($batch_ids as $batch_id) {
            $revisions = DB::table('link_building_order_placement_revisions')
                ->where('batch_id', $batch_id)
                ->orderBy('id')
                ->get();

            $first = $revisions->first();

            DB::table('link_building_order_history_batches')->insert([
                'batch_id'          => $batch_id,
                'source'            => $first->source,
                'user_id'           => $first->user_id,
                'restored_batch_id' => $first->restored_batch_id,
                'revisions_count'   => $revisions->count(),
                'rows_count'        => $revisions->pluck('placement_id')->unique()->count(),
                'cells_count'       => $revisions->sum(fn ($r) => count(json_decode($r->cell_changes ?? '[]', true) ?: [])),
                'created_count'     => $revisions->where('event', 'created')->count(),
                'updated_count'     => $revisions->where('event', 'updated')->count(),
                'deleted_count'     => $revisions->where('event', 'deleted')->count(),
                'order_ids_preview' => json_encode($revisions->pluck('order_id')->filter()->unique()->take(5)->values()->all()),
                'started_at'        => $first->created_at,
                'last_change_at'    => $revisions->last()->created_at,
            ]);
        }

        // Actions that were restored already.
        $restores = DB::table('link_building_order_placement_revisions')
            ->whereNotNull('restored_batch_id')
            ->selectRaw('restored_batch_id, MIN(created_at) as restored_at, MIN(user_id) as user_id')
            ->groupBy('restored_batch_id')
            ->get();

        foreach ($restores as $restore) {
            DB::table('link_building_order_history_batches')
                ->where('batch_id', $restore->restored_batch_id)
                ->update(['restored_at' => $restore->restored_at, 'restored_by_id' => $restore->user_id]);
        }
    }
};
