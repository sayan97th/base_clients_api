<?php

namespace App\Http\Controllers\Admin\LinkBuilding;

use App\Events\LinkBuildingOrderCreated;
use App\Events\LinkBuildingOrderDeleted;
use App\Events\LinkBuildingOrderUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LinkBuilding\StoreLinkBuildingOrderRequest;
use App\Http\Requests\Admin\LinkBuilding\UpdateLinkBuildingOrderRequest;
use App\Jobs\ProcessLinkBuildingImportJob;
use App\Mail\OrderStatusChangeMail;
use App\Models\LinkBuildingOrderPlacement;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LinkBuildingOrdersDashboardController extends Controller
{
    /**
     * DB columns that may appear as a sort_rules key.
     * Computed-only fields (days_left, projected_health) are intentionally excluded.
     */
    private const ALLOWED_SORT_COLUMNS = [
        'order_id', 'team_specific_link_id', 'link_type', 'client', 'keyword',
        'landing_page', 'exact_match', 'notes', 'internal_notes', 'request_date', 'estimated_delivery_date',
        'estimated_turnaround_days', 'link_builder', 'pen_name', 'partnership', 'partnership_check',
        'article_title', 'article', 'status', 'live_link', 'live_link_date',
        'dr_lbs', 'posting_fee_lbs', 'current_traffic', 'dr_formula',
        'current_poc', 'current_price', 'lb_tl_approval', 'approval_date', 'final_price',
        'currency',
    ];

    /** All columns that may be targeted by column_filters. */
    private const FILTERABLE_COLUMNS = [
        'order_id', 'team_specific_link_id', 'link_type', 'client', 'keyword',
        'landing_page', 'exact_match', 'notes', 'internal_notes', 'request_date', 'estimated_delivery_date',
        'estimated_turnaround_days', 'link_builder', 'pen_name', 'partnership', 'partnership_check',
        'article_title', 'article', 'status', 'live_link', 'live_link_date',
        'dr_lbs', 'posting_fee_lbs', 'current_traffic', 'dr_formula',
        'current_poc', 'current_price', 'lb_tl_approval', 'approval_date', 'final_price',
        'currency',
    ];

    /**
     * POST /api/admin/link-building-orders/search
     *
     * Paginated list with server-side filtering and multi-column sorting.
     * Returns all dashboard-visible placements: admin-created (order_id set),
     * client-purchased (order_item_id set), and admin-assigned (user_id set).
     */
    public function search(Request $request): JsonResponse
    {
        $page           = max(1, (int) $request->input('page', 1));
        $per_page       = min((int) $request->input('per_page', 50), 200);
        $search         = $request->input('search');
        $status         = $request->input('status');
        $link_type      = $request->input('link_type');
        $client         = $request->input('client');
        $link_builder   = $request->input('link_builder');
        $sort_rules     = $request->input('sort_rules', []);
        $column_filters = $request->input('column_filters', []);

        // Include all visible placements: admin-created (order_id), client-purchased
        // (order_item_id), or admin-assigned to a client (user_id).
        $query = LinkBuildingOrderPlacement::with(['orderItem.order.user', 'adminTeam', 'assignedAdminUser'])
            ->where(function ($q) {
                $q->whereNotNull('order_id')
                  ->orWhereNotNull('order_item_id')
                  ->orWhereNotNull('user_id');
            });

        $this->applyGlobalSearch($query, $search);
        $this->applyQuickFilters($query, $status, $link_type, $client, $link_builder);
        $this->applyColumnFilters($query, $column_filters);
        $this->applySortRules($query, $sort_rules);

        $paginated = $query->paginate($per_page, ['*'], 'page', $page);

        $data = $paginated->getCollection()
            ->map(fn (LinkBuildingOrderPlacement $p) => $p->toApiArray())
            ->values();

        return response()->json([
            'data'         => $data,
            'current_page' => $paginated->currentPage(),
            'last_page'    => $paginated->lastPage(),
            'per_page'     => $paginated->perPage(),
            'total'        => $paginated->total(),
            'from'         => $paginated->firstItem(),
            'to'           => $paginated->lastItem(),
        ]);
    }

    /**
     * POST /api/admin/link-building-orders
     *
     * Creates a new link building order row on the dashboard.
     */
    public function store(StoreLinkBuildingOrderRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (empty($data['request_date'])) {
            $data['request_date'] = Carbon::today()->format('m/d/Y');
        }

        if (empty($data['estimated_delivery_date'])) {
            $turnaround = max(1, (int) ($data['estimated_turnaround_days'] ?? 30));
            try {
                $base_date = Carbon::createFromFormat('m/d/Y', $data['request_date']);
            } catch (\Exception) {
                $base_date = Carbon::today();
            }
            $data['estimated_delivery_date'] = $base_date->addDays($turnaround)->format('m/d/Y');
        }

        $placement  = LinkBuildingOrderPlacement::create($data);
        $session_id = $request->header('X-Session-ID');

        broadcast(new LinkBuildingOrderCreated($placement, $session_id));

        return response()->json([
            'message' => 'Link building order created successfully.',
            'data'    => $placement->toApiArray(),
        ], 201);
    }

    /**
     * PUT /api/admin/link-building-orders/{id}
     *
     * Fully replaces all fields of an existing link building order.
     * When a client-purchased placement status changes, the parent LinkBuildingOrder
     * status is synced automatically and an email is sent to the client.
     */
    public function update(UpdateLinkBuildingOrderRequest $request, string $id): JsonResponse
    {
        $placement = LinkBuildingOrderPlacement::find($id);

        if (! $placement) {
            return response()->json(['message' => 'Link building order not found.'], 404);
        }

        $old_status = $placement->status;
        $placement->update($request->validated());

        /** @var LinkBuildingOrderPlacement $fresh */
        $fresh      = $placement->fresh();
        $session_id = $request->header('X-Session-ID');

        broadcast(new LinkBuildingOrderUpdated($fresh, $session_id));

        if ($fresh->status !== $old_status) {
            if ($fresh->order_item_id) {
                $this->syncParentOrderStatus($fresh);
            } elseif ($fresh->user_id) {
                $this->notifyAssignedUser($fresh, $old_status);
            }
        }

        return response()->json([
            'message' => 'Link building order updated successfully.',
            'data'    => $fresh->toApiArray(),
        ]);
    }

    /**
     * POST /api/admin/link-building-orders/batch-update
     *
     * Updates one or more fields across multiple rows in a single request.
     * Only explicitly whitelisted columns are accepted to prevent mass-assignment abuse.
     *
     * Request body:
     *   row_ids : string[]                     — IDs of placements to update
     *   updates : Record<string, string|null>  — field → value map
     */
    public function batchUpdate(Request $request): JsonResponse
    {
        $row_ids = (array) ($request->input('row_ids') ?? []);
        $updates = (array) ($request->input('updates') ?? []);

        $allowed_fields = [
            'status', 'link_type', 'client', 'keyword', 'landing_page', 'exact_match',
            'notes', 'internal_notes', 'team_specific_link_id', 'pen_name',
            'partnership', 'partnership_check', 'article_title', 'article',
            'live_link', 'live_link_date', 'dr_lbs', 'posting_fee_lbs',
            'current_traffic', 'dr_formula', 'current_poc', 'current_price',
            'lb_tl_approval', 'approval_date', 'final_price', 'currency',
            'assigned_admin_user_id',
        ];

        $safe_updates = array_filter(
            $updates,
            fn ($key) => in_array($key, $allowed_fields, true),
            ARRAY_FILTER_USE_KEY
        );

        if (empty($row_ids) || empty($safe_updates)) {
            return response()->json(['message' => 'Nothing to update.'], 422);
        }

        $placements = LinkBuildingOrderPlacement::whereIn('id', $row_ids)->get();
        $session_id = $request->header('X-Session-ID');

        foreach ($placements as $placement) {
            $placement->update($safe_updates);
            broadcast(new LinkBuildingOrderUpdated($placement->fresh(), $session_id));
        }

        return response()->json([
            'message'       => "Updated {$placements->count()} row(s) successfully.",
            'updated_count' => $placements->count(),
        ]);
    }

    /**
     * DELETE /api/admin/link-building-orders/{id}
     *
     * Permanently deletes a link building order row.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $placement = LinkBuildingOrderPlacement::find($id);

        if (! $placement) {
            return response()->json(['message' => 'Link building order not found.'], 404);
        }

        $session_id = $request->header('X-Session-ID');

        $placement->delete();

        broadcast(new LinkBuildingOrderDeleted($id, $session_id));

        return response()->json(['message' => 'Link building order deleted successfully.']);
    }

    /**
     * GET /api/admin/link-building-orders/assignable-users
     *
     * Lightweight list of admin-side users (super_admin, admin, staff) for the
     * "Assigned To" dropdown in the link building orders dashboard.
     */
    public function assignableUsers(): JsonResponse
    {
        $users = User::whereHas('roles', fn ($q) => $q->whereIn('name', ['super_admin', 'admin', 'staff']))
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'email', 'profile_photo_url'])
            ->map(fn (User $u) => [
                'id'         => $u->id,
                'name'       => trim($u->first_name . ' ' . $u->last_name),
                'email'      => $u->email,
                'avatar_url' => $u->profile_photo_url,
            ]);

        return response()->json(['data' => $users->values()]);
    }

    /**
     * POST /api/admin/link-building-orders/import
     *
     * Accepts a CSV file upload, stores it, and dispatches a background job
     * that upserts rows by order_id (no duplicates). Returns an import_id
     * that the caller can poll via /import-status/{import_id}.
     */
    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:51200'],
        ]);

        $file      = $request->file('file');
        $import_id = Str::uuid()->toString();

        $stored_path = $file->storeAs(
            'imports/link-building',
            $import_id . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file->getClientOriginalName())
        );

        $total_rows = max(0, $this->countCsvRows(storage_path('app/' . $stored_path)) - 1);

        Cache::put("lbo_import_{$import_id}", [
            'status'    => 'queued',
            'total'     => $total_rows,
            'processed' => 0,
            'created'   => 0,
            'updated'   => 0,
            'skipped'   => 0,
            'errors'    => [],
        ], now()->addHours(2));

        ProcessLinkBuildingImportJob::dispatch($import_id, $stored_path, $total_rows);

        return response()->json([
            'message'   => 'Import queued successfully.',
            'import_id' => $import_id,
            'total'     => $total_rows,
        ], 202);
    }

    /**
     * GET /api/admin/link-building-orders/import-status/{import_id}
     *
     * Returns the current progress of a running or completed import job.
     */
    public function importStatus(string $import_id): JsonResponse
    {
        $progress = Cache::get("lbo_import_{$import_id}");

        if ($progress === null) {
            return response()->json(['message' => 'Import not found or expired.'], 404);
        }

        return response()->json($progress);
    }

    /**
     * POST /api/admin/link-building-orders/export
     *
     * Streams a CSV download of all rows matching the same filters as /search.
     */
    public function export(Request $request): StreamedResponse
    {
        $search         = $request->input('search');
        $status         = $request->input('status');
        $link_type      = $request->input('link_type');
        $client         = $request->input('client');
        $link_builder   = $request->input('link_builder');
        $sort_rules     = $request->input('sort_rules', []);
        $column_filters = $request->input('column_filters', []);

        $query = LinkBuildingOrderPlacement::with(['orderItem.order.user', 'adminTeam', 'assignedAdminUser'])
            ->where(function ($q) {
                $q->whereNotNull('order_id')
                  ->orWhereNotNull('order_item_id')
                  ->orWhereNotNull('user_id');
            });

        $this->applyGlobalSearch($query, $search);
        $this->applyQuickFilters($query, $status, $link_type, $client, $link_builder);
        $this->applyColumnFilters($query, $column_filters);
        $this->applySortRules($query, $sort_rules);

        $filename = 'link-building-orders-' . now()->format('Y-m-d') . '.csv';

        $headers = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $columns = [
            'order_id', 'status', 'team_specific_link_id', 'link_type', 'client', 'keyword',
            'landing_page', 'exact_match', 'notes', 'internal_notes', 'request_date', 'estimated_delivery_date',
            'estimated_turnaround_days', 'days_left', 'projected_health', 'link_builder',
            'pen_name', 'partnership', 'partnership_check', 'article_title', 'article', 'live_link', 'live_link_date',
            'dr_lbs', 'posting_fee_lbs', 'current_traffic', 'dr_formula', 'current_poc',
            'current_price', 'lb_tl_approval', 'approval_date', 'final_price', 'currency',
        ];

        $callback = function () use ($query, $columns) {
            $handle = fopen('php://output', 'w');

            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM
            fputcsv($handle, $columns);

            $query->chunk(500, function ($placements) use ($handle, $columns) {
                foreach ($placements as $placement) {
                    $row = $placement->toApiArray();
                    fputcsv($handle, array_map(fn ($col) => $row[$col] ?? '', $columns));
                }
            });

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }

    // ── Order status sync & notifications ────────────────────────────────────

    /**
     * Syncs the parent LinkBuildingOrder status after a placement status change.
     *
     * Rules:
     *   - All placements "Live"  → order becomes "completed"
     *   - Any other combination  → order becomes "processing"
     *
     * An email is queued to the client only when the order status actually changes.
     */
    private function syncParentOrderStatus(LinkBuildingOrderPlacement $placement): void
    {
        $placement->loadMissing('orderItem.order.user');

        $order_item   = $placement->orderItem;
        $parent_order = $order_item?->order;

        if (! $parent_order) {
            return;
        }

        $all_statuses = LinkBuildingOrderPlacement::whereHas('orderItem', function ($q) use ($parent_order) {
            $q->where('order_id', $parent_order->id);
        })->pluck('status');

        $new_order_status = $all_statuses->every(fn ($s) => $s === 'Live')
            ? 'completed'
            : 'processing';

        if ($parent_order->status === $new_order_status) {
            return;
        }

        $parent_order->update(['status' => $new_order_status]);

        $user = $parent_order->user;

        if ($user) {
            Mail::to($user->email)->queue(
                new OrderStatusChangeMail(
                    user: $user,
                    new_status: $new_order_status,
                    order_id: $parent_order->id,
                )
            );
        }
    }

    /**
     * Sends a status-change notification email to the user directly assigned to a standalone
     * placement (user_id set, no order_item_id).
     *
     * To keep notifications meaningful, emails are sent only on two transitions:
     *   - Any status → "Live"            → notifies as "completed"
     *   - "New Request" → anything else  → notifies as "processing" (work has started)
     */
    private function notifyAssignedUser(LinkBuildingOrderPlacement $placement, string $old_status): void
    {
        $placement->loadMissing('user');

        $user = $placement->user;

        if (! $user) {
            return;
        }

        $new_status = $placement->status;

        if ($new_status === 'Live') {
            $order_status = 'completed';
        } elseif ($old_status === 'New Request') {
            $order_status = 'processing';
        } else {
            return;
        }

        $display_order_id = $placement->order_id
            ?? 'LBO-' . strtoupper(substr(str_replace('-', '', $placement->id), 0, 10));

        Mail::to($user->email)->queue(
            new OrderStatusChangeMail(
                user: $user,
                new_status: $order_status,
                order_id: $display_order_id,
            )
        );
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Counts the number of lines in a CSV file efficiently without loading it into memory.
     */
    private function countCsvRows(string $file_path): int
    {
        $count  = 0;
        $handle = @fopen($file_path, 'r');

        if ($handle === false) {
            return 0;
        }

        while (!feof($handle)) {
            fgets($handle);
            $count++;
        }

        fclose($handle);

        return $count;
    }

    private function applyGlobalSearch($query, ?string $search): void
    {
        if (! filled($search)) {
            return;
        }

        $query->where(function ($q) use ($search) {
            $q->where('order_id', 'like', '%' . $search . '%')
                ->orWhere('client', 'like', '%' . $search . '%')
                ->orWhere('keyword', 'like', '%' . $search . '%')
                ->orWhere('link_builder', 'like', '%' . $search . '%')
                ->orWhere('status', 'like', '%' . $search . '%')
                ->orWhere('partnership', 'like', '%' . $search . '%');
        });
    }

    private function applyQuickFilters($query, ?string $status, ?string $link_type, ?string $client, ?string $link_builder): void
    {
        if (filled($status)) {
            $query->where('status', $status);
        }

        if (filled($link_type)) {
            $query->where('link_type', $link_type);
        }

        if (filled($client)) {
            $query->where('client', 'like', '%' . $client . '%');
        }

        if (filled($link_builder)) {
            $query->where('link_builder', 'like', '%' . $link_builder . '%');
        }
    }

    private function applyColumnFilters($query, array $column_filters): void
    {
        foreach ($column_filters as $filter) {
            $key  = $filter['key']  ?? '';
            $type = $filter['type'] ?? '';

            if (! in_array($key, self::FILTERABLE_COLUMNS, true)) {
                continue;
            }

            match ($type) {
                'text'   => $this->applyTextFilter($query, $key, $filter),
                'select' => $this->applySelectFilter($query, $key, $filter),
                'number' => $this->applyNumberFilter($query, $key, $filter),
                'date'   => $this->applyDateFilter($query, $key, $filter),
                default  => null,
            };
        }
    }

    private function applyTextFilter($query, string $key, array $filter): void
    {
        $value = $filter['value'] ?? '';
        if (filled($value)) {
            $query->where($key, 'like', '%' . $value . '%');
        }
    }

    private function applySelectFilter($query, string $key, array $filter): void
    {
        $values = $filter['values'] ?? [];
        if (! empty($values)) {
            $query->whereIn($key, $values);
        }
    }

    private function applyNumberFilter($query, string $key, array $filter): void
    {
        $min = $filter['min'] ?? '';
        $max = $filter['max'] ?? '';

        if (filled($min)) {
            $query->where($key, '>=', (float) $min);
        }

        if (filled($max)) {
            $query->where($key, '<=', (float) $max);
        }
    }

    private function applyDateFilter($query, string $key, array $filter): void
    {
        $from = $filter['from'] ?? '';
        $to   = $filter['to']   ?? '';

        if (filled($from)) {
            try {
                $from_date = Carbon::createFromFormat('m/d/Y', $from)->startOfDay();
                $query->whereRaw("STR_TO_DATE(`{$key}`, '%m/%d/%Y') >= ?", [$from_date->toDateString()]);
            } catch (\Exception) {
                // Invalid date — skip this bound
            }
        }

        if (filled($to)) {
            try {
                $to_date = Carbon::createFromFormat('m/d/Y', $to)->startOfDay();
                $query->whereRaw("STR_TO_DATE(`{$key}`, '%m/%d/%Y') <= ?", [$to_date->toDateString()]);
            } catch (\Exception) {
                // Invalid date — skip this bound
            }
        }
    }

    private function applySortRules($query, array $sort_rules): void
    {
        $unsortable = ['days_left', 'projected_health'];
        $applied    = false;

        foreach ($sort_rules as $rule) {
            $key        = $rule['key']       ?? '';
            $dir        = strtolower($rule['direction'] ?? 'asc');
            $nulls_last = (bool) ($rule['nulls_last'] ?? false);

            if (in_array($key, $unsortable, true)) {
                continue;
            }

            if (! in_array($key, self::ALLOWED_SORT_COLUMNS, true)) {
                continue;
            }

            if (! in_array($dir, ['asc', 'desc'], true)) {
                continue;
            }

            if ($nulls_last) {
                $query->orderByRaw("(`{$key}` IS NULL OR `{$key}` = ''), `{$key}` {$dir}");
            } else {
                $query->orderBy($key, $dir);
            }

            $applied = true;
        }

        if (! $applied) {
            $query->orderBy('id', 'desc');
        }
    }
}
