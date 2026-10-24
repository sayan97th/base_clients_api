<?php

namespace App\Http\Controllers\Admin\LinkBuilding;

use App\Http\Controllers\Controller;
use App\Models\LinkBuildingOrderPlacement;
use App\Models\LinkBuildingOrderPlacementRevision;
use App\Services\LinkBuildingDomainUsageService;
use App\Services\LinkBuildingOrderHistoryRestoreService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;

/**
 * Version history of the Link Building Orders table: every change grouped by the
 * action that made it (an edit, a paste, an import), the edit history of a single
 * row, and restoring either one. See LinkBuildingOrderHistoryRecorder for how the
 * history is written and LinkBuildingOrderHistoryRestoreService for the restore.
 */
class LinkBuildingOrderHistoryController extends Controller
{
    /** Revisions sent with each action of the list, the rest load with batch(). */
    private const PREVIEW_REVISIONS = 25;

    private const DEFAULT_PER_PAGE   = 20;
    private const MAX_PER_PAGE       = 50;
    private const MAX_BATCH_PER_PAGE = 200;

    private const USER_COLUMNS = 'user:id,first_name,last_name,email';

    public function __construct(
        private readonly LinkBuildingOrderHistoryRestoreService $restore_service,
        private readonly LinkBuildingDomainUsageService $domain_usage,
    ) {}

    /**
     * GET /api/admin/link-building-orders/history
     *
     * Paginated list of actions, newest first, each with its first revisions.
     *
     * Query: page, per_page, source, user_id, order_id (partial BL- id match)
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page'     => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:' . self::MAX_PER_PAGE],
            'source'   => ['sometimes', 'nullable', 'string', Rule::in(LinkBuildingOrderPlacementRevision::SOURCES)],
            'user_id'  => ['sometimes', 'nullable', 'integer'],
            'order_id' => ['sometimes', 'nullable', 'string', 'max:50'],
        ]);

        $filters = fn (): Builder => LinkBuildingOrderPlacementRevision::query()
            ->when($validated['source'] ?? null, fn (Builder $q, string $source) => $q->where('source', $source))
            ->when($validated['user_id'] ?? null, fn (Builder $q, int $user_id) => $q->where('user_id', $user_id))
            ->when(
                filled($validated['order_id'] ?? null),
                fn (Builder $q) => $q->where('order_id', 'like', '%' . addcslashes(trim($validated['order_id']), '%_\\') . '%')
            );

        /** @var LengthAwarePaginator $batches */
        $batches = $filters()
            ->selectRaw(implode(', ', [
                'batch_id',
                'MAX(id) as last_revision_id',
                'MIN(created_at) as started_at',
                'COUNT(*) as revisions_count',
                'COUNT(DISTINCT placement_id) as rows_count',
                "SUM(CASE WHEN event = 'created' THEN 1 ELSE 0 END) as created_count",
                "SUM(CASE WHEN event = 'updated' THEN 1 ELSE 0 END) as updated_count",
                "SUM(CASE WHEN event = 'deleted' THEN 1 ELSE 0 END) as deleted_count",
            ]))
            ->groupBy('batch_id')
            ->orderByDesc('last_revision_id')
            ->paginate($validated['per_page'] ?? self::DEFAULT_PER_PAGE);

        $batch_ids = $batches->getCollection()->pluck('batch_id')->all();
        $restores  = $this->restoresOf($batch_ids);

        $data = $batches->getCollection()->map(function ($batch) use ($filters, $restores) {
            $revisions = $filters()
                ->where('batch_id', $batch->batch_id)
                ->with(self::USER_COLUMNS)
                ->orderBy('id')
                ->limit(self::PREVIEW_REVISIONS)
                ->get();

            return $this->batchSummary($batch, $revisions, $restores[$batch->batch_id] ?? null);
        });

        return response()->json([
            'data' => $data->values()->all(),
            'meta' => $this->paginationMeta($batches),
        ]);
    }

    /**
     * GET /api/admin/link-building-orders/history/batches/{batch_id}
     *
     * Every revision of one action, oldest first, for actions too large to fit in
     * the list preview (a paste or an import over many rows).
     */
    public function batch(Request $request, string $batch_id): JsonResponse
    {
        $per_page = min(max((int) $request->input('per_page', 100), 1), self::MAX_BATCH_PER_PAGE);

        $revisions = LinkBuildingOrderPlacementRevision::where('batch_id', $batch_id)
            ->with(self::USER_COLUMNS)
            ->orderBy('id')
            ->paginate($per_page);

        if ($revisions->total() === 0) {
            return response()->json(['message' => 'Change not found in the history.'], 404);
        }

        return response()->json([
            'data' => $revisions->getCollection()->map(fn (LinkBuildingOrderPlacementRevision $r) => $r->toApiArray())->all(),
            'meta' => $this->paginationMeta($revisions),
        ]);
    }

    /**
     * GET /api/admin/link-building-orders/{id}/history
     *
     * Edit history of one row, newest first, like "Show edit history" on a cell.
     * Works for deleted rows too, their history is kept.
     */
    public function placement(Request $request, string $id): JsonResponse
    {
        $per_page = min(max((int) $request->input('per_page', 25), 1), self::MAX_PER_PAGE);

        $revisions = LinkBuildingOrderPlacementRevision::where('placement_id', $id)
            ->with(self::USER_COLUMNS)
            ->orderByDesc('id')
            ->paginate($per_page);

        $placement = LinkBuildingOrderPlacement::find($id);

        return response()->json([
            'data'       => $revisions->getCollection()->map(fn (LinkBuildingOrderPlacementRevision $r) => $r->toApiArray())->all(),
            'meta'       => $this->paginationMeta($revisions),
            'order_id'   => $placement?->displayOrderId() ?? $revisions->getCollection()->first()?->order_id,
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
     * conflicts and the strategy is "abort".
     */
    public function restore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'batch_id'          => ['required_without:revision_id', 'nullable', 'string', 'max:64'],
            'revision_id'       => ['required_without:batch_id', 'nullable', 'integer'],
            'conflict_strategy' => ['sometimes', 'string', Rule::in(LinkBuildingOrderHistoryRestoreService::CONFLICT_STRATEGIES)],
        ]);

        $batch_id = $validated['batch_id'] ?? null;

        $revisions = $batch_id !== null
            ? LinkBuildingOrderPlacementRevision::where('batch_id', $batch_id)->orderBy('id')->get()
            : LinkBuildingOrderPlacementRevision::whereKey($validated['revision_id'])->get();

        if ($revisions->isEmpty()) {
            return response()->json(['message' => 'Change not found in the history.'], 404);
        }

        if ($revisions->every(fn ($r) => $r->event === LinkBuildingOrderPlacementRevision::EVENT_CREATED)) {
            return response()->json([
                'message' => 'This change only added rows. Restoring never deletes rows, delete them from the table instead.',
            ], 422);
        }

        $result = $this->restore_service->restore(
            $revisions,
            $validated['conflict_strategy'] ?? LinkBuildingOrderHistoryRestoreService::CONFLICTS_ABORT,
            $request->header('X-Session-ID'),
            $batch_id,
        );

        $rows = [...$result['updated'], ...$result['recreated']];
        $this->domain_usage->annotate($rows);

        $conflict_count = count($result['conflicts']);

        return response()->json([
            'message'        => $result['applied']
                ? $this->restoredMessage($result['restored_cells'], count($result['recreated']))
                : "{$conflict_count} cell(s) were edited again after this change. Choose whether to skip or overwrite them.",
            'applied'        => $result['applied'],
            'restored_cells' => $result['restored_cells'],
            'recreated_rows' => count($result['recreated']),
            'conflicts'      => $result['conflicts'],
            'skipped'        => $result['skipped'],
            'data'           => array_map(fn (LinkBuildingOrderPlacement $p) => $p->toApiArray(), $rows),
            'recreated_ids'  => array_map(fn (LinkBuildingOrderPlacement $p) => $p->id, $result['recreated']),
        ], $result['applied'] ? 200 : 409);
    }

    /**
     * When and by whom each of the given batches was restored, keyed by batch id.
     *
     * @return array<string, array{restored_at: string, user_id: ?int}>
     */
    private function restoresOf(array $batch_ids): array
    {
        if (empty($batch_ids)) {
            return [];
        }

        return LinkBuildingOrderPlacementRevision::whereIn('restored_batch_id', $batch_ids)
            ->selectRaw('restored_batch_id, MIN(created_at) as restored_at, MIN(user_id) as user_id')
            ->groupBy('restored_batch_id')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->restored_batch_id => [
                'restored_at' => (string) $row->restored_at,
                'user_id'     => $row->user_id === null ? null : (int) $row->user_id,
            ]])
            ->all();
    }

    private function batchSummary(object $batch, $revisions, ?array $restore): array
    {
        $first = $revisions->first();

        return [
            'batch_id'           => $batch->batch_id,
            'source'             => $first?->source,
            'user'               => $first?->userSummary(),
            'started_at'         => $first?->created_at?->toIso8601String(),
            'revisions_count'    => (int) $batch->revisions_count,
            'rows_count'         => (int) $batch->rows_count,
            'created_count'      => (int) $batch->created_count,
            'updated_count'      => (int) $batch->updated_count,
            'deleted_count'      => (int) $batch->deleted_count,
            // Only known when every revision of the action is in the preview.
            'cells_count'        => (int) $batch->revisions_count > $revisions->count()
                ? null
                : $revisions->sum(fn ($r) => count($r->cell_changes ?? [])),
            'restored_batch_id'  => $first?->restored_batch_id,
            'restored'           => $restore,
            'revisions'          => $revisions->map(fn (LinkBuildingOrderPlacementRevision $r) => $r->toApiArray())->all(),
            'has_more_revisions' => (int) $batch->revisions_count > $revisions->count(),
        ];
    }

    private function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page'    => $paginator->lastPage(),
            'per_page'     => $paginator->perPage(),
            'total'        => $paginator->total(),
        ];
    }

    private function restoredMessage(int $cells, int $rows): string
    {
        $parts = [];
        if ($cells > 0) {
            $parts[] = "{$cells} cell(s)";
        }
        if ($rows > 0) {
            $parts[] = "{$rows} deleted row(s)";
        }

        return empty($parts)
            ? 'Nothing to restore, the table already has these values.'
            : 'Restored ' . implode(' and ', $parts) . '.';
    }
}
