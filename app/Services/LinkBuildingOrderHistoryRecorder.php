<?php

namespace App\Services;

use App\Models\LinkBuildingOrderPlacement;
use App\Models\LinkBuildingOrderPlacementRevision;
use Carbon\Carbon;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes the edit history of the Link Building Orders table, see
 * LinkBuildingOrderPlacementRevision. Bound as a scoped singleton: the entry
 * point of an action (a controller method, an import job) calls begin() once,
 * and every placement change made while handling it lands in the same batch, so
 * the whole action can be reviewed and restored together.
 *
 * Changes made through Eloquent are recorded by LinkBuildingOrderPlacementObserver.
 * Bulk writes that bypass the model (imports, "clear all") go through
 * captureBulkWrite() and deleteWithHistory() instead.
 */
class LinkBuildingOrderHistoryRecorder
{
    /** Rows written per INSERT when recording many revisions at once. */
    private const INSERT_CHUNK_SIZE = 500;

    private string $source = 'system';

    private ?string $batch_id = null;

    /** Set by queued jobs, which run without an authenticated user. */
    private ?int $user_id = null;

    /** Batch being restored, when the current action is a restore. */
    private ?string $restored_batch_id = null;

    /**
     * Starts a new action. Returns its batch id.
     */
    public function begin(string $source, ?string $batch_id = null, ?int $user_id = null, ?string $restored_batch_id = null): string
    {
        $this->source            = in_array($source, LinkBuildingOrderPlacementRevision::SOURCES, true) ? $source : 'system';
        $this->batch_id          = $batch_id ?? (string) Str::uuid();
        $this->user_id           = $user_id;
        $this->restored_batch_id = $restored_batch_id;

        return $this->batch_id;
    }

    public function batchId(): string
    {
        return $this->batch_id ??= (string) Str::uuid();
    }

    /** Columns whose changes are recorded: every value an admin or the client can set. */
    public static function trackedColumns(): array
    {
        return (new LinkBuildingOrderPlacement())->getFillable();
    }

    /** Tracked values of a placement, normalized so they compare and serialize the same way everywhere. */
    public static function attributesOf(LinkBuildingOrderPlacement $placement): array
    {
        $attributes = [];
        foreach (self::trackedColumns() as $column) {
            $attributes[$column] = self::normalize($placement->getAttribute($column));
        }

        return $attributes;
    }

    /** Whether two recorded values are the same cell value. */
    public static function sameValue(mixed $a, mixed $b): bool
    {
        if (is_array($a) || is_array($b)) {
            // An empty formulas map and no formulas map mean the same thing.
            return ($a ?: null) == ($b ?: null);
        }

        if ($a === null || $b === null) {
            return $a === $b;
        }

        if (is_bool($a) || is_bool($b)) {
            return (bool) $a === (bool) $b;
        }

        return (string) $a === (string) $b;
    }

    public function recordCreated(LinkBuildingOrderPlacement $placement): void
    {
        $this->write([$this->revisionRow($placement->id, $placement->order_id, LinkBuildingOrderPlacementRevision::EVENT_CREATED, null, $this->snapshotOf($placement))]);
    }

    /** Called from the "updated" model event, while getOriginal() still holds the previous values. */
    public function recordUpdated(LinkBuildingOrderPlacement $placement): void
    {
        $tracked = array_flip(self::trackedColumns());
        $changes = [];

        foreach (array_keys($placement->getChanges()) as $column) {
            if (! isset($tracked[$column])) {
                continue;
            }

            $old = self::normalize($placement->getOriginal($column));
            $new = self::normalize($placement->getAttribute($column));

            if (! self::sameValue($old, $new)) {
                $changes[$column] = ['old' => $old, 'new' => $new];
            }
        }

        if (empty($changes)) {
            return;
        }

        // An order id edit is recorded under the id the row had before it.
        $order_id = $placement->getOriginal('order_id') ?? $placement->order_id;

        $this->write([$this->revisionRow($placement->id, $order_id, LinkBuildingOrderPlacementRevision::EVENT_UPDATED, $changes, null)]);
    }

    public function recordDeleted(LinkBuildingOrderPlacement $placement): void
    {
        $this->write([$this->revisionRow($placement->id, $placement->order_id, LinkBuildingOrderPlacementRevision::EVENT_DELETED, null, $this->snapshotOf($placement))]);
    }

    /**
     * Runs a bulk write that bypasses the model (an import chunk) and records what
     * it changed on the rows with the given order ids, by comparing them before
     * and after the write. Returns whatever the write returns.
     */
    public function captureBulkWrite(array $order_ids, Closure $write): mixed
    {
        $order_ids = array_values(array_unique(array_filter($order_ids, fn ($id) => $id !== null && $id !== '')));
        if (empty($order_ids)) {
            return $write();
        }

        $before = $this->placementsByOrderId($order_ids)->keyBy('id');
        $result = $write();
        $after  = $this->placementsByOrderId($order_ids);

        $rows = [];
        foreach ($after as $placement) {
            $previous = $before->get($placement->id);

            if (! $previous) {
                $rows[] = $this->revisionRow($placement->id, $placement->order_id, LinkBuildingOrderPlacementRevision::EVENT_CREATED, null, $this->snapshotOf($placement));
                continue;
            }

            $old_attributes = self::attributesOf($previous);
            $new_attributes = self::attributesOf($placement);
            $changes        = [];

            foreach ($new_attributes as $column => $new) {
                if (! self::sameValue($old_attributes[$column], $new)) {
                    $changes[$column] = ['old' => $old_attributes[$column], 'new' => $new];
                }
            }

            if (! empty($changes)) {
                $rows[] = $this->revisionRow($placement->id, $previous->order_id, LinkBuildingOrderPlacementRevision::EVENT_UPDATED, $changes, null);
            }
        }

        $this->write($rows);

        return $result;
    }

    /**
     * Deletes every placement the query matches without loading them one by one,
     * keeping a "deleted" revision with the full row for each, so a mass delete
     * can be restored like any other change. Returns the number of deleted rows.
     */
    public function deleteWithHistory(Builder $query): int
    {
        $deleted_count = 0;

        $query->chunkById(self::INSERT_CHUNK_SIZE, function (Collection $placements) use (&$deleted_count) {
            DB::transaction(function () use ($placements, &$deleted_count) {
                $this->write($placements->map(fn (LinkBuildingOrderPlacement $placement) => $this->revisionRow(
                    $placement->id,
                    $placement->order_id,
                    LinkBuildingOrderPlacementRevision::EVENT_DELETED,
                    null,
                    $this->snapshotOf($placement),
                ))->all());

                $deleted_count += LinkBuildingOrderPlacement::whereIn('id', $placements->modelKeys())->delete();
            });
        });

        return $deleted_count;
    }

    /** Full row kept with creates and deletes, enough to recreate the row with the same id. */
    private function snapshotOf(LinkBuildingOrderPlacement $placement): array
    {
        return [
            'id'         => $placement->id,
            ...self::attributesOf($placement),
            'created_at' => self::normalize($placement->created_at),
        ];
    }

    private function placementsByOrderId(array $order_ids): Collection
    {
        return LinkBuildingOrderPlacement::whereIn('order_id', $order_ids)->get();
    }

    private function revisionRow(string $placement_id, ?string $order_id, string $event, ?array $changes, ?array $snapshot): array
    {
        return [
            'batch_id'          => $this->batchId(),
            'placement_id'      => $placement_id,
            'order_id'          => $order_id,
            'event'             => $event,
            'source'            => $this->source,
            'cell_changes'      => $changes === null ? null : json_encode($changes),
            'snapshot'          => $snapshot === null ? null : json_encode($snapshot),
            'restored_batch_id' => $this->restored_batch_id,
            'user_id'           => $this->user_id ?? auth()->id(),
            'created_at'        => now(),
        ];
    }

    private function write(array $rows): void
    {
        foreach (array_chunk($rows, self::INSERT_CHUNK_SIZE) as $chunk) {
            LinkBuildingOrderPlacementRevision::insert($chunk);
        }
    }

    private static function normalize(mixed $value): mixed
    {
        return $value instanceof DateTimeInterface ? Carbon::instance($value)->toDateTimeString() : $value;
    }
}
