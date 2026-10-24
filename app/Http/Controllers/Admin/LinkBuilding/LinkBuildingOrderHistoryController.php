<?php

namespace App\Http\Controllers\Admin\LinkBuilding;

use App\Http\Controllers\Controller;
use App\Jobs\RestoreLinkBuildingOrderHistoryJob;
use App\Models\LinkBuildingOrderHistoryBatch;
use App\Models\LinkBuildingOrderPlacement;
use App\Models\LinkBuildingOrderPlacementRevision;
use App\Services\LinkBuildingOrderHistoryRestoreService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Version history of the Link Building Orders table: every action that changed it
 * (an edit, a paste, an import), the edit history of a single row, and restoring
 * either one. See LinkBuildingOrderHistoryRecorder for how the history is written
 * and LinkBuildingOrderHistoryRestoreService for the restore.
 *
 * Built to stay fast with a large table and a long history:
 *   - the list reads one summary row per action (LinkBuildingOrderHistoryBatch),
 *     never the revisions themselves;
 *   - the revisions of an action load only when it is opened, a page at a time;
 *   - every list pages with a cursor (the id of the last entry seen), which
 *     costs the same on the first page as on the thousandth, and skips the
 *     total count that a large table would have to scan for;
 *   - restoring a large action runs on the queue.
 */
class LinkBuildingOrderHistoryController extends Controller
{
    private const DEFAULT_PER_PAGE = 20;
    private const MAX_PER_PAGE     = 50;

    /** Revisions per page when an action is opened. */
    private const REVISIONS_PER_PAGE     = 100;
    private const MAX_REVISIONS_PER_PAGE = 200;

    private const USER_COLUMNS = 'id,first_name,last_name,email';

    public function __construct(
        private readonly LinkBuildingOrderHistoryRestoreService $restore_service,
    ) {}

    /**
     * GET /api/admin/link-building-orders/history
     *
     * Actions newest first, as summaries without their revisions.
     *
     * Query: before_id (cursor: id of the last action already shown), per_page,
     *        source, user_id, order_id (an Order ID or its start, e.g. "BL-257")
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'before_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'per_page'  => ['sometimes', 'integer', 'min:1', 'max:' . self::MAX_PER_PAGE],
            'source'    => ['sometimes', 'nullable', 'string', Rule::in(LinkBuildingOrderPlacementRevision::SOURCES)],
            'user_id'   => ['sometimes', 'nullable', 'integer'],
            'order_id'  => ['sometimes', 'nullable', 'string', 'max:50'],
        ]);

        $per_page        = $validated['per_page'] ?? self::DEFAULT_PER_PAGE;
        $order_id_prefix = $this->orderIdPrefix($validated['order_id'] ?? null);

        $batches = LinkBuildingOrderHistoryBatch::query()
            ->with(['user:' . self::USER_COLUMNS, 'restoredBy:' . self::USER_COLUMNS])
            ->when($validated['before_id'] ?? null, fn (Builder $q, int $before_id) => $q->where('id', '<', $before_id))
            ->when($validated['source'] ?? null, fn (Builder $q, string $source) => $q->where('source', $source))
            ->when($validated['user_id'] ?? null, fn (Builder $q, int $user_id) => $q->where('user_id', $user_id))
            ->when($order_id_prefix !== null, fn (Builder $q) => $q->whereIn(
                'batch_id',
                LinkBuildingOrderPlacementRevision::select('batch_id')->where('order_id', 'like', $order_id_prefix . '%')
            ))
            ->orderByDesc('id')
            ->limit($per_page + 1)
            ->get();

        return response()->json([
            'data' => $batches->take($per_page)->map(fn (LinkBuildingOrderHistoryBatch $b) => $b->toApiArray())->values()->all(),
            'meta' => $this->cursorMeta($batches, $per_page, 'next_before_id'),
        ]);
    }

    /**
     * GET /api/admin/link-building-orders/history/batches/{batch_id}
     *
     * Revisions of one action, oldest first, a page at a time.
     *
     * Query: after_id (cursor: id of the last revision already shown), per_page
     */
    public function batch(Request $request, string $batch_id): JsonResponse
    {
        $per_page = min(max((int) $request->input('per_page', self::REVISIONS_PER_PAGE), 1), self::MAX_REVISIONS_PER_PAGE);
        $after_id = (int) $request->input('after_id', 0);

        $revisions = LinkBuildingOrderPlacementRevision::where('batch_id', $batch_id)
            ->when($after_id > 0, fn (Builder $q) => $q->where('id', '>', $after_id))
            ->with('user:' . self::USER_COLUMNS)
            ->orderBy('id')
            ->limit($per_page + 1)
            ->get();

        if ($revisions->isEmpty() && $after_id === 0) {
            return response()->json(['message' => 'Change not found in the history.'], 404);
        }

        return response()->json([
            'data' => $revisions->take($per_page)->map(fn (LinkBuildingOrderPlacementRevision $r) => $r->toApiArray())->values()->all(),
            'meta' => $this->cursorMeta($revisions, $per_page, 'next_after_id'),
        ]);
    }

    /**
     * GET /api/admin/link-building-orders/{id}/history
     *
     * Edit history of one row, newest first, like "Show edit history" on a cell.
     * Works for deleted rows too, their history is kept.
     *
     * Query: before_id (cursor: id of the last revision already shown), per_page
     */
    public function placement(Request $request, string $id): JsonResponse
    {
        $per_page  = min(max((int) $request->input('per_page', 25), 1), self::MAX_PER_PAGE);
        $before_id = (int) $request->input('before_id', 0);

        $revisions = LinkBuildingOrderPlacementRevision::where('placement_id', $id)
            ->when($before_id > 0, fn (Builder $q) => $q->where('id', '<', $before_id))
            ->with('user:' . self::USER_COLUMNS)
            ->orderByDesc('id')
            ->limit($per_page + 1)
            ->get();

        $placement = LinkBuildingOrderPlacement::find($id);

        return response()->json([
            'data'       => $revisions->take($per_page)->map(fn (LinkBuildingOrderPlacementRevision $r) => $r->toApiArray())->values()->all(),
            'meta'       => $this->cursorMeta($revisions, $per_page, 'next_before_id'),
            'order_id'   => $placement?->displayOrderId() ?? $revisions->first()?->order_id,
            'is_deleted' => $placement === null,
        ]);
    }

    /**
     * POST /api/admin/link-building-orders/history/restore
     *
     * Restores a whole action (batch_id) or a single row change (revision_id).
     *
     * Body:
     *   batch_id          : string, restores every change of one action, or
     *   revision_id       : int, restores one change of one row
     *   conflict_strategy : "abort" (default) | "skip" | "overwrite", what to do with
     *                       cells edited again after the change being restored
     *
     * Responds 409 with the conflicts and without changing anything when there are
     * conflicts and the strategy is "abort". An action larger than
     * config('link_building.history_sync_restore_max_revisions') is restored on the
     * queue: the answer is 202 with a restore_id to poll with restoreStatus().
     */
    public function restore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'batch_id'          => ['required_without:revision_id', 'nullable', 'string', 'max:64'],
            'revision_id'       => ['required_without:batch_id', 'nullable', 'integer'],
            'conflict_strategy' => ['sometimes', 'string', Rule::in(LinkBuildingOrderHistoryRestoreService::CONFLICT_STRATEGIES)],
        ]);

        $batch_id = $validated['batch_id'] ?? null;
        $strategy = $validated['conflict_strategy'] ?? LinkBuildingOrderHistoryRestoreService::CONFLICTS_ABORT;

        $query = $batch_id !== null
            ? LinkBuildingOrderPlacementRevision::where('batch_id', $batch_id)
            : LinkBuildingOrderPlacementRevision::whereKey($validated['revision_id']);

        $events = (clone $query)->selectRaw('event, COUNT(*) as total')->groupBy('event')->pluck('total', 'event');

        if ($events->isEmpty()) {
            return response()->json(['message' => 'Change not found in the history.'], 404);
        }

        if ($events->keys()->all() === [LinkBuildingOrderPlacementRevision::EVENT_CREATED]) {
            return response()->json([
                'message' => 'This change only added rows. Restoring never deletes rows, delete them from the table instead.',
            ], 422);
        }

        if ($batch_id !== null && $events->sum() > config('link_building.history_sync_restore_max_revisions')) {
            $restore_id = (string) Str::uuid();
            RestoreLinkBuildingOrderHistoryJob::markQueued($restore_id, $request->user()->id);
            RestoreLinkBuildingOrderHistoryJob::dispatch($restore_id, $batch_id, $strategy, $request->user()->id, $request->header('X-Session-ID'));

            return response()->json([
                'message'    => 'This change is large, it is being restored in the background.',
                'queued'     => true,
                'restore_id' => $restore_id,
            ], 202);
        }

        $result = $this->restore_service->restore(
            $query->orderBy('id')->get(),
            $strategy,
            $request->header('X-Session-ID'),
            $batch_id,
        );

        return response()->json($this->restore_service->toPayload($result), $result['applied'] ? 200 : 409);
    }

    /**
     * GET /api/admin/link-building-orders/history/restores/{restore_id}
     *
     * Status of a restore running on the queue: queued, processing, completed
     * (with the same answer as an immediate restore in "result") or failed.
     * Only the admin who started it can read it.
     */
    public function restoreStatus(Request $request, string $restore_id): JsonResponse
    {
        $status = Cache::get(RestoreLinkBuildingOrderHistoryJob::cacheKey($restore_id));

        if (! $status || ($status['user_id'] ?? null) !== $request->user()->id) {
            return response()->json(['message' => 'Restore not found.'], 404);
        }

        unset($status['user_id']);

        return response()->json($status);
    }

    /**
     * Cursor of the next page: the id of the last entry shown, when the query
     * found one more entry than the page holds.
     */
    private function cursorMeta(Collection $entries, int $per_page, string $cursor_key): array
    {
        $has_more = $entries->count() > $per_page;

        return [
            'per_page'  => $per_page,
            'has_more'  => $has_more,
            $cursor_key => $has_more ? $entries->get($per_page - 1)->id : null,
        ];
    }

    /**
     * An Order ID search as an index friendly prefix: "BL-257" and "257" both look
     * for "BL-257...". Null when there is nothing to search for.
     */
    private function orderIdPrefix(?string $search): ?string
    {
        $search = strtoupper(trim((string) $search));
        if ($search === '') {
            return null;
        }

        if (ctype_digit($search)) {
            $search = 'BL-' . $search;
        }

        return addcslashes($search, '%_\\');
    }
}
