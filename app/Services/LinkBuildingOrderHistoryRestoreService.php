<?php

namespace App\Services;

use App\Events\LinkBuildingOrderCreated;
use App\Events\LinkBuildingOrderUpdated;
use App\Models\LinkBuildingOrderPlacement;
use App\Models\LinkBuildingOrderPlacementRevision;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Puts the Link Building Orders table back to how it was before one or more
 * revisions of its edit history, like "Restore this version" in Google Sheets
 * but for a single action instead of the whole sheet.
 *
 * Each changed cell goes back to the value it had before the first revision that
 * touched it, and a deleted row is recreated with its original id. A cell edited
 * again after those revisions is a conflict: by default nothing is restored and
 * the conflicts are returned, so the admin can decide to skip those cells or to
 * overwrite the later edits. Restoring never deletes rows, and the restore is
 * itself recorded in the history, so it can be restored back.
 */
class LinkBuildingOrderHistoryRestoreService
{
    public const CONFLICTS_ABORT     = 'abort';
    public const CONFLICTS_SKIP      = 'skip';
    public const CONFLICTS_OVERWRITE = 'overwrite';

    public const CONFLICT_STRATEGIES = [self::CONFLICTS_ABORT, self::CONFLICTS_SKIP, self::CONFLICTS_OVERWRITE];

    /** Why a cell or row could not be restored at all. */
    public const SKIP_ROW_DELETED     = 'row_deleted';
    public const SKIP_ORDER_ID_IN_USE = 'order_id_in_use';

    public function __construct(
        private readonly LinkBuildingOrderHistoryRecorder $history,
        private readonly LinkBuildingParentOrderStatusService $parent_order_status,
    ) {}

    /**
     * @param  Collection<int, LinkBuildingOrderPlacementRevision>  $revisions
     * @param  string|null  $restored_batch_id  set when the revisions are a whole batch
     * @return array{
     *     applied: bool,
     *     conflicts: list<array<string, mixed>>,
     *     skipped: list<array<string, mixed>>,
     *     restored_cells: int,
     *     updated: list<LinkBuildingOrderPlacement>,
     *     recreated: list<LinkBuildingOrderPlacement>,
     * }
     */
    public function restore(Collection $revisions, string $conflict_strategy, ?string $session_id = null, ?string $restored_batch_id = null): array
    {
        $plans      = $this->buildPlans($revisions);
        $placements = LinkBuildingOrderPlacement::whereIn('id', array_keys($plans))->get()->keyBy('id');

        $conflicts = [];
        $skipped   = [];
        $work      = [];

        foreach ($plans as $placement_id => $plan) {
            $placement = $placements->get($placement_id);
            $recreate  = null;

            if (! $placement) {
                if ($plan['snapshot'] === null) {
                    $skipped[] = $this->skipEntry($placement_id, $plan['order_id'], null, self::SKIP_ROW_DELETED);
                    continue;
                }

                $snapshot_order_id = $plan['snapshot']['order_id'] ?? null;
                if ($snapshot_order_id !== null && LinkBuildingOrderPlacement::where('order_id', $snapshot_order_id)->exists()) {
                    $skipped[] = $this->skipEntry($placement_id, $snapshot_order_id, 'order_id', self::SKIP_ORDER_ID_IN_USE);
                    continue;
                }

                $recreate = $plan['snapshot'];
            }

            $current = $recreate ?? LinkBuildingOrderHistoryRecorder::attributesOf($placement);
            $values  = [];

            foreach ($plan['fields'] as $field => $target) {
                $current_value = $current[$field] ?? null;

                if (LinkBuildingOrderHistoryRecorder::sameValue($current_value, $target['old'])) {
                    continue;
                }

                if ($field === 'order_id' && $target['old'] !== null
                    && LinkBuildingOrderPlacement::where('order_id', $target['old'])->where('id', '!=', $placement_id)->exists()) {
                    $skipped[] = $this->skipEntry($placement_id, $current['order_id'] ?? $plan['order_id'], $field, self::SKIP_ORDER_ID_IN_USE);
                    continue;
                }

                if (! LinkBuildingOrderHistoryRecorder::sameValue($current_value, $target['new'])) {
                    $conflicts[] = [
                        'placement_id'   => $placement_id,
                        'order_id'       => $current['order_id'] ?? $plan['order_id'],
                        'field'          => $field,
                        'current_value'  => $current_value,
                        'expected_value' => $target['new'],
                        'restore_value'  => $target['old'],
                    ];

                    if ($conflict_strategy !== self::CONFLICTS_OVERWRITE) {
                        continue;
                    }
                }

                $values[$field] = $target['old'];
            }

            if ($recreate !== null || ! empty($values)) {
                $work[] = ['placement' => $placement, 'recreate' => $recreate, 'values' => $values];
            }
        }

        if ($conflict_strategy === self::CONFLICTS_ABORT && ! empty($conflicts)) {
            return $this->result(false, $conflicts, $skipped, 0, [], []);
        }

        [$updated, $recreated, $restored_cells] = $this->apply($work, $restored_batch_id);

        foreach ($recreated as $placement) {
            broadcast(new LinkBuildingOrderCreated($placement, $session_id));
        }

        foreach ($updated as $placement) {
            broadcast(new LinkBuildingOrderUpdated($placement, $session_id));
        }

        // A restored status keeps the client's order in step, but a correction is not
        // news for the client, so no status email is sent.
        foreach ([...$updated, ...$recreated] as $placement) {
            if ($placement->order_item_id && ($placement->wasRecentlyCreated || $placement->wasChanged('status'))) {
                $this->parent_order_status->sync($placement, notify_client: false);
            }
        }

        return $this->result(true, $conflicts, $skipped, $restored_cells, $updated, $recreated);
    }

    /**
     * What each row needs to go back to: per field the value before the first
     * revision that changed it ("old") and after the last one ("new", what the cell
     * should still hold if nobody edited it since), plus the row itself when the
     * revisions deleted it.
     *
     * @return array<string, array{order_id: ?string, fields: array<string, array{old: mixed, new: mixed}>, snapshot: ?array}>
     */
    private function buildPlans(Collection $revisions): array
    {
        $plans = [];

        foreach ($revisions->sortBy('id') as $revision) {
            $plan = $plans[$revision->placement_id] ?? ['order_id' => $revision->order_id, 'fields' => [], 'snapshot' => null];

            if ($revision->event === LinkBuildingOrderPlacementRevision::EVENT_UPDATED) {
                foreach ($revision->cell_changes ?? [] as $field => $change) {
                    $plan['fields'][$field] = [
                        'old' => array_key_exists($field, $plan['fields']) ? $plan['fields'][$field]['old'] : ($change['old'] ?? null),
                        'new' => $change['new'] ?? null,
                    ];
                }
            } elseif ($revision->event === LinkBuildingOrderPlacementRevision::EVENT_DELETED) {
                $plan['snapshot'] = $revision->snapshot;
            }

            // Creations are not undone: restoring never deletes a row.
            $plans[$revision->placement_id] = $plan;
        }

        return array_filter($plans, fn (array $plan) => ! empty($plan['fields']) || $plan['snapshot'] !== null);
    }

    /**
     * @return array{0: list<LinkBuildingOrderPlacement>, 1: list<LinkBuildingOrderPlacement>, 2: int}
     */
    private function apply(array $work, ?string $restored_batch_id): array
    {
        $this->history->begin('restore', restored_batch_id: $restored_batch_id);

        return DB::transaction(function () use ($work) {
            $updated        = [];
            $recreated      = [];
            $restored_cells = 0;

            foreach ($work as $item) {
                $placement = $item['placement'];

                if ($item['recreate'] !== null) {
                    $placement = new LinkBuildingOrderPlacement();
                    $placement->forceFill($item['recreate'])->save();
                    $recreated[] = $placement;
                }

                if (! empty($item['values'])) {
                    $placement->forceFill($item['values'])->save();
                    $restored_cells += count($item['values']);

                    if ($item['recreate'] === null) {
                        $updated[] = $placement;
                    }
                }
            }

            return [$updated, $recreated, $restored_cells];
        });
    }

    private function skipEntry(string $placement_id, ?string $order_id, ?string $field, string $reason): array
    {
        return ['placement_id' => $placement_id, 'order_id' => $order_id, 'field' => $field, 'reason' => $reason];
    }

    private function result(bool $applied, array $conflicts, array $skipped, int $restored_cells, array $updated, array $recreated): array
    {
        return [
            'applied'        => $applied,
            'conflicts'      => $conflicts,
            'skipped'        => $skipped,
            'restored_cells' => $restored_cells,
            'updated'        => $updated,
            'recreated'      => $recreated,
        ];
    }
}
