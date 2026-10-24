<?php

namespace App\Services;

use App\Events\LinkBuildingOrderCreated;
use App\Events\LinkBuildingOrderUpdated;
use App\Events\LinkBuildingOrdersBulkChanged;
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
        private readonly LinkBuildingDomainUsageService $domain_usage,
    ) {}

    /** Rows looked up per query while planning a restore. */
    private const LOOKUP_CHUNK_SIZE = 1000;

    /**
     * @param  Collection<int, LinkBuildingOrderPlacementRevision>  $revisions
     * @param  string|null  $restored_batch_id  set when the revisions are a whole batch
     * @param  int|null  $user_id  who restores, for queued restores that run without a login
     * @return array{
     *     applied: bool,
     *     conflicts: list<array<string, mixed>>,
     *     skipped: list<array<string, mixed>>,
     *     restored_cells: int,
     *     updated: list<LinkBuildingOrderPlacement>,
     *     recreated: list<LinkBuildingOrderPlacement>,
     * }
     */
    public function restore(
        Collection $revisions,
        string $conflict_strategy,
        ?string $session_id = null,
        ?string $restored_batch_id = null,
        ?int $user_id = null,
    ): array {
        $plans      = $this->buildPlans($revisions);
        $placements = $this->placementsById(array_keys($plans));
        $order_ids  = $this->orderIdOwners($plans);

        $conflicts = [];
        $skipped   = [];
        $work      = [];

        foreach ($plans as $placement_id => $plan) {
            $placement = $placements[$placement_id] ?? null;
            $recreate  = null;

            if (! $placement) {
                if ($plan['snapshot'] === null) {
                    $skipped[] = $this->skipEntry($placement_id, $plan['order_id'], null, self::SKIP_ROW_DELETED);
                    continue;
                }

                $snapshot_order_id = $plan['snapshot']['order_id'] ?? null;
                if ($snapshot_order_id !== null && isset($order_ids[$snapshot_order_id])) {
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
                    && isset($order_ids[$target['old']]) && $order_ids[$target['old']] !== $placement_id) {
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

        [$updated, $recreated, $restored_cells] = $this->apply($work, $restored_batch_id, $user_id);

        $this->broadcastRestoredRows($updated, $recreated, $session_id);
        $this->syncParentOrders([...$updated, ...$recreated]);

        return $this->result(true, $conflicts, $skipped, $restored_cells, $updated, $recreated);
    }

    /**
     * Tells the open tables about the restored rows: one event per row for a small
     * restore, a single "reload" event for a large one, where thousands of row
     * events would flood every browser.
     *
     * @param  list<LinkBuildingOrderPlacement>  $updated
     * @param  list<LinkBuildingOrderPlacement>  $recreated
     */
    private function broadcastRestoredRows(array $updated, array $recreated, ?string $session_id): void
    {
        $row_count = count($updated) + count($recreated);

        if ($row_count === 0) {
            return;
        }

        if ($row_count > config('link_building.history_restore_row_events_max')) {
            broadcast(new LinkBuildingOrdersBulkChanged($session_id, $row_count));
            return;
        }

        foreach ($recreated as $placement) {
            broadcast(new LinkBuildingOrderCreated($placement, $session_id));
        }

        foreach ($updated as $placement) {
            broadcast(new LinkBuildingOrderUpdated($placement, $session_id));
        }
    }

    /**
     * A restored status keeps the client's order in step, once per order however
     * many of its placements were restored. A correction is not news for the
     * client, so no status email is sent.
     *
     * @param  list<LinkBuildingOrderPlacement>  $placements
     */
    private function syncParentOrders(array $placements): void
    {
        $synced_order_ids = [];

        foreach ($placements as $placement) {
            if (! $placement->order_item_id || ! ($placement->wasRecentlyCreated || $placement->wasChanged('status'))) {
                continue;
            }

            $placement->loadMissing('orderItem');
            $order_id = $placement->orderItem?->order_id;

            if ($order_id === null || isset($synced_order_ids[$order_id])) {
                continue;
            }

            $synced_order_ids[$order_id] = true;
            $this->parent_order_status->sync($placement, notify_client: false);
        }
    }

    /** @return array<string, LinkBuildingOrderPlacement> */
    private function placementsById(array $ids): array
    {
        $placements = [];

        foreach (array_chunk($ids, self::LOOKUP_CHUNK_SIZE) as $chunk) {
            foreach (LinkBuildingOrderPlacement::whereIn('id', $chunk)->get() as $placement) {
                $placements[$placement->id] = $placement;
            }
        }

        return $placements;
    }

    /**
     * Rows that hold any Order ID the restore could put back, keyed by Order ID, so
     * a recreated row or a restored Order ID never collides with another row.
     *
     * @return array<string, string>  order_id => id of the row holding it
     */
    private function orderIdOwners(array $plans): array
    {
        $candidates = [];
        foreach ($plans as $plan) {
            if (isset($plan['snapshot']['order_id'])) {
                $candidates[] = $plan['snapshot']['order_id'];
            }
            if (isset($plan['fields']['order_id']['old'])) {
                $candidates[] = $plan['fields']['order_id']['old'];
            }
        }

        $owners = [];
        foreach (array_chunk(array_values(array_unique($candidates)), self::LOOKUP_CHUNK_SIZE) as $chunk) {
            $owners += LinkBuildingOrderPlacement::whereIn('order_id', $chunk)->pluck('id', 'order_id')->all();
        }

        return $owners;
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
    private function apply(array $work, ?string $restored_batch_id, ?int $user_id): array
    {
        $this->history->begin('restore', user_id: $user_id, restored_batch_id: $restored_batch_id);

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

    /** Conflicts and skipped rows listed in a restore answer, the rest are only counted. */
    public const MAX_LISTED_ISSUES = 100;

    /**
     * API answer of a restore, the same whether it ran in the request or on the
     * queue. Small restores send the restored rows back for the table to merge.
     * Large ones send none and ask the table to reload, like their broadcast does.
     */
    public function toPayload(array $result): array
    {
        $rows           = [...$result['updated'], ...$result['recreated']];
        $reload         = count($rows) > config('link_building.history_restore_row_events_max');
        $conflict_count = count($result['conflicts']);

        if (! $reload) {
            $this->domain_usage->annotate($rows);
        }

        return [
            'message'         => $result['applied']
                ? $this->restoredMessage($result['restored_cells'], count($result['recreated']))
                : "{$conflict_count} cell(s) were edited again after this change. Choose whether to skip or overwrite them.",
            'applied'         => $result['applied'],
            'restored_cells'  => $result['restored_cells'],
            'recreated_rows'  => count($result['recreated']),
            'conflicts'       => array_slice($result['conflicts'], 0, self::MAX_LISTED_ISSUES),
            'conflicts_count' => $conflict_count,
            'skipped'         => array_slice($result['skipped'], 0, self::MAX_LISTED_ISSUES),
            'skipped_count'   => count($result['skipped']),
            'reload_required' => $reload,
            'data'            => $reload ? [] : array_map(fn (LinkBuildingOrderPlacement $p) => $p->toApiArray(), $rows),
            'recreated_ids'   => $reload ? [] : array_map(fn (LinkBuildingOrderPlacement $p) => $p->id, $result['recreated']),
        ];
    }

    private function restoredMessage(int $cells, int $rows): string
    {
        $parts = [];
        if ($cells > 0) {
            $parts[] = number_format($cells) . ' cell(s)';
        }
        if ($rows > 0) {
            $parts[] = number_format($rows) . ' deleted row(s)';
        }

        return empty($parts)
            ? 'Nothing to restore, the table already has these values.'
            : 'Restored ' . implode(' and ', $parts) . '.';
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
