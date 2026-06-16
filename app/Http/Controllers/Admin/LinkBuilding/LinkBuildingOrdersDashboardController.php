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

    /** Columns stored as MM/DD/YYYY strings that require STR_TO_DATE for correct sort order. */
    private const DATE_COLUMNS = [
        'request_date', 'estimated_delivery_date', 'live_link_date', 'approval_date',
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
        $page              = max(1, (int) $request->input('page', 1));
        $per_page          = min((int) $request->input('per_page', 50), 500);
        $search            = $request->input('search');
        $status            = $request->input('status');
        $link_type         = $request->input('link_type');
        $client            = $request->input('client');
        $link_builder      = $request->input('link_builder');
        $client_user_id    = $request->input('client_user_id');
        $assigned_user_id  = $request->input('assigned_user_id');
        $sort_rules        = $request->input('sort_rules', []);
        $column_filters    = $request->input('column_filters', []);

        // Include all visible placements: admin-created (order_id), client-purchased
        // (order_item_id), or admin-assigned to a client (user_id).
        $query = LinkBuildingOrderPlacement::with(['orderItem.order.user', 'user', 'adminTeam', 'assignedAdminUser'])
            ->where(function ($q) {
                $q->whereNotNull('order_id')
                  ->orWhereNotNull('order_item_id')
                  ->orWhereNotNull('user_id');
            });

        $this->applyGlobalSearch($query, $search);
        $this->applyQuickFilters($query, $status, $link_type, $client, $link_builder, $client_user_id, $assigned_user_id);
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

        // Always generate a server-side BL-{n} order_id regardless of any client value.
        $data['order_id'] = $this->generateNextOrderId();

        if (empty($data['request_date'])) {
            $data['request_date'] = Carbon::today()->format('m/d/Y');
        }

        if (empty($data['estimated_delivery_date'])) {
            try {
                $base_date = Carbon::createFromFormat('m/d/Y', $data['request_date']);
            } catch (\Exception) {
                $base_date = Carbon::today();
            }
            $data['estimated_delivery_date'] = $base_date->addDays(30)->format('m/d/Y');
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
            'assigned_admin_user_id', 'user_id',
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
     * DELETE /api/admin/link-building-orders
     *
     * Permanently deletes ALL admin-created link building orders (those with an order_id set).
     * This is a "clean slate" operation intended to be run before a fresh CSV import so the
     * portal table contains only the newly imported data.
     *
     * Client-purchased placements (order_item_id set without order_id) are not affected.
     */
    public function clearAll(): JsonResponse
    {
        $deleted_count = LinkBuildingOrderPlacement::whereNotNull('order_id')->delete();

        return response()->json([
            'message'       => "Cleared {$deleted_count} link building order(s) successfully.",
            'deleted_count' => $deleted_count,
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
     * GET /api/admin/link-building-orders/assignable-clients
     *
     * Lightweight list of client users for the "Client Account" dropdown in the
     * link building orders dashboard. Allows admins to link an order to a specific
     * registered client account in the application.
     */
    public function assignableClients(): JsonResponse
    {
        $clients = User::whereHas('roles', fn ($q) => $q->where('name', 'client'))
            ->orderByRaw("CASE WHEN TRIM(COALESCE(first_name, '')) = '' AND TRIM(COALESCE(last_name, '')) = '' THEN 1 ELSE 0 END")
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'email', 'profile_photo_url'])
            ->map(fn (User $u) => [
                'id'         => $u->id,
                'name'       => trim($u->first_name . ' ' . $u->last_name),
                'email'      => $u->email,
                'avatar_url' => $u->profile_photo_url,
            ]);

        return response()->json(['data' => $clients->values()]);
    }

    /**
     * POST /api/admin/link-building-orders/import
     *
     * Accepts a CSV file upload, stores it, and dispatches a background job
     * that upserts rows by order_id (no duplicates). Returns an import_id
     * that the caller can poll via /import-status/{import_id}.
     *
     * Optional filter parameters:
     *   apply_date_filter  (bool, default true)  — when false, no date restriction is applied
     *   date_from          (string MM/DD/YYYY)    — lower bound for request_date
     *   date_to            (string MM/DD/YYYY)    — upper bound for request_date
     *   link_type_filter   (string)               — 'external_only' (default) | 'internal_only' | 'all'
     */
    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file'             => ['required', 'file', 'mimes:csv,txt', 'max:51200'],
            'apply_date_filter'=> ['sometimes', 'boolean'],
            'date_from'        => ['sometimes', 'nullable', 'string'],
            'date_to'          => ['sometimes', 'nullable', 'string'],
            'link_type_filter' => ['sometimes', 'string', 'in:external_only,internal_only,all'],
        ]);

        $file      = $request->file('file');
        $import_id = Str::uuid()->toString();

        // Date range defaults: only a lower bound (last year) is applied automatically.
        // No automatic upper bound — defaulting date_to to "today" would silently reject
        // legitimately future-dated request_date values (e.g. orders scheduled ahead),
        // which caused valid records to be skipped without explanation.
        $apply_date_filter = filter_var($request->input('apply_date_filter', true), FILTER_VALIDATE_BOOLEAN);
        $date_from         = null;
        $date_to           = null;

        if ($apply_date_filter) {
            $date_from = filled($request->input('date_from'))
                ? $request->input('date_from')
                : Carbon::now()->subYear()->format('m/d/Y');

            $date_to = filled($request->input('date_to'))
                ? $request->input('date_to')
                : null;
        }

        $link_type_filter = $request->input('link_type_filter', 'external_only');

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

        ProcessLinkBuildingImportJob::dispatch($import_id, $stored_path, $total_rows, $date_from, $date_to, $link_type_filter);

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
     * Streams a CSV download of rows matching the same filters as /search.
     * When `row_ids` is provided and non-empty, only those specific rows are exported
     * and all other filter parameters are ignored.
     */
    public function export(Request $request): StreamedResponse
    {
        $row_ids           = (array) ($request->input('row_ids') ?? []);
        $search            = $request->input('search');
        $status            = $request->input('status');
        $link_type         = $request->input('link_type');
        $client            = $request->input('client');
        $link_builder      = $request->input('link_builder');
        $client_user_id    = $request->input('client_user_id');
        $assigned_user_id  = $request->input('assigned_user_id');
        $sort_rules        = $request->input('sort_rules', []);
        $column_filters    = $request->input('column_filters', []);

        $query = LinkBuildingOrderPlacement::with(['orderItem.order.user', 'user', 'adminTeam', 'assignedAdminUser'])
            ->where(function ($q) {
                $q->whereNotNull('order_id')
                  ->orWhereNotNull('order_item_id')
                  ->orWhereNotNull('user_id');
            });

        if (! empty($row_ids)) {
            // Export only the explicitly requested rows, preserving their selection order.
            $query->whereIn('id', $row_ids);
        } else {
            $this->applyGlobalSearch($query, $search);
            $this->applyQuickFilters($query, $status, $link_type, $client, $link_builder, $client_user_id, $assigned_user_id);
            $this->applyColumnFilters($query, $column_filters);
            $this->applySortRules($query, $sort_rules);
        }

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

    /**
     * POST /api/admin/link-building-orders/resolve-assignments
     *
     * Scans all existing placements that have a link_builder value and attempts to
     * resolve each one to an admin-side user by name (same matching logic used during
     * CSV import). Rows that already carry a matching assigned_admin_user_id are
     * left unchanged. Useful after a bulk import that predates the auto-assign feature.
     *
     * Returns:
     *   resolved  — number of rows where assigned_admin_user_id was set or updated
     *   unchanged — number of rows whose assignment was already correct or had no match
     */
    public function resolveAssignments(): JsonResponse
    {
        $admin_user_name_map = $this->buildAdminUserNameMap();

        $resolved  = 0;
        $unchanged = 0;

        LinkBuildingOrderPlacement::whereNotNull('link_builder')
            ->where('link_builder', '!=', '')
            ->chunk(500, function ($placements) use ($admin_user_name_map, &$resolved, &$unchanged) {
                foreach ($placements as $placement) {
                    $admin_user_id = $this->resolveAdminUserIdFromText(
                        trim((string) $placement->link_builder),
                        $admin_user_name_map
                    );

                    if ($admin_user_id !== null && (int) $admin_user_id !== (int) $placement->assigned_admin_user_id) {
                        $placement->update(['assigned_admin_user_id' => $admin_user_id]);
                        $resolved++;
                    } else {
                        $unchanged++;
                    }
                }
            });

        return response()->json([
            'message'   => "Resolved {$resolved} assignment(s).",
            'resolved'  => $resolved,
            'unchanged' => $unchanged,
        ]);
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
            ?? 'BL-' . strtoupper(substr(str_replace('-', '', $placement->id), 0, 10));

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
     * Generates the next sequential order ID in the BL-{n} format.
     * Delegates to the model so cart checkout and dashboard creation share the same sequence.
     */
    private function generateNextOrderId(): string
    {
        return LinkBuildingOrderPlacement::generateNextOrderId();
    }

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

    private function applyQuickFilters($query, ?string $status, ?string $link_type, ?string $client, ?string $link_builder, $client_user_id = null, $assigned_user_id = null): void
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

        if (filled($client_user_id)) {
            $query->where('user_id', (int) $client_user_id);
        }

        if (filled($assigned_user_id)) {
            $query->where('assigned_admin_user_id', (int) $assigned_user_id);
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

    /**
     * Builds a map of normalized name strings → user_id for admin-side users,
     * including email-derived alternative last-name entries so that CSV values such
     * as "Anderson, Kaitlin" resolve to a user whose current last name differs
     * from their email alias (e.g. Kaitlin Ogden, email kaitlinanderson@...).
     */
    private function buildAdminUserNameMap(): array
    {
        $map = [];

        User::whereHas('roles', fn ($q) => $q->whereIn('name', ['super_admin', 'admin', 'staff']))
            ->get(['id', 'first_name', 'last_name', 'email'])
            ->each(function (User $u) use (&$map) {
                $first = strtolower(trim((string) $u->first_name));
                $last  = strtolower(trim((string) $u->last_name));

                if ($first !== '' && $last !== '') {
                    $map["{$first} {$last}"] = $u->id;
                    $map["{$last} {$first}"] = $u->id;
                    $map[$last]              = $u->id;
                } elseif ($last !== '') {
                    $map[$last] = $u->id;
                } elseif ($first !== '') {
                    $map[$first] = $u->id;
                }

                // Email prefix fallback (e.g. kaitlinanderson → first=kaitlin, email_last=anderson)
                if ($first !== '' && filled($u->email)) {
                    $email_prefix = strtolower(preg_replace('/[^a-z]/i', '', explode('@', $u->email)[0]));
                    if ($email_prefix !== '' && str_starts_with($email_prefix, $first) && strlen($email_prefix) > strlen($first)) {
                        $email_last = substr($email_prefix, strlen($first));
                        if ($email_last !== '' && $email_last !== $last) {
                            $map["{$first} {$email_last}"] = $u->id;
                            $map["{$email_last} {$first}"] = $u->id;
                            $map[$email_last]               = $u->id;
                        }
                    }
                }
            });

        return $map;
    }

    /**
     * Parses a raw "Link Builder" value and returns the matching admin user ID, or null.
     * Supports formats: "2. Allan, Abigail", "1. Coley, Tyler", "Tyler Coley", "Allan".
     */
    private function resolveAdminUserIdFromText(string $raw, array $admin_user_name_map): ?int
    {
        $value = preg_replace('/^\d+\.\s*/', '', $raw);
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (str_contains($value, ',')) {
            [$last, $first] = array_map('trim', explode(',', $value, 2));
            $last  = strtolower($last);
            $first = strtolower($first);

            if ($first !== '' && $last !== '' && isset($admin_user_name_map["{$first} {$last}"])) {
                return $admin_user_name_map["{$first} {$last}"];
            }
            if ($last !== '' && $first !== '' && isset($admin_user_name_map["{$last} {$first}"])) {
                return $admin_user_name_map["{$last} {$first}"];
            }
            if ($last !== '' && isset($admin_user_name_map[$last])) {
                return $admin_user_name_map[$last];
            }

            return null;
        }

        $normalized = strtolower($value);
        if (isset($admin_user_name_map[$normalized])) {
            return $admin_user_name_map[$normalized];
        }

        $parts = preg_split('/\s+/', $normalized);
        if (is_array($parts) && count($parts) >= 2) {
            $last_word = end($parts);
            if ($last_word !== false && isset($admin_user_name_map[$last_word])) {
                return $admin_user_name_map[$last_word];
            }
        }

        return null;
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

            if ($key === 'order_id') {
                // Natural numeric sort: extracts the trailing digit sequence from the order_id
                // (e.g. "BL-9999" → 9999, "BL-25009" → 25009) and compares numerically so that
                // "BL-25009" correctly outranks "BL-9999" in descending order.
                // IDs with no trailing digits (e.g. alphanumeric LBO suffixes) resolve to 0
                // and are disambiguated by the full order_id string as a secondary key.
                $query->orderByRaw(
                    "CAST(REGEXP_SUBSTR(`order_id`, '[0-9]+\$') AS UNSIGNED) {$dir}, `order_id` {$dir}"
                );
            } elseif (in_array($key, self::DATE_COLUMNS, true)) {
                // Dates are stored as MM/DD/YYYY strings; use STR_TO_DATE so the database
                // compares actual calendar values instead of doing an alphabetical sort.
                $query->orderByRaw(
                    "STR_TO_DATE(NULLIF(`{$key}`, ''), '%m/%d/%Y') {$dir}"
                );
            } elseif ($nulls_last) {
                $query->orderByRaw("(`{$key}` IS NULL OR `{$key}` = ''), `{$key}` {$dir}");
            } else {
                $query->orderBy($key, $dir);
            }

            $applied = true;
        }

        if (! $applied) {
            // Default: most recent request_date first. STR_TO_DATE is required because
            // dates are stored as MM/DD/YYYY strings, not native DATE columns.
            $query->orderByRaw("STR_TO_DATE(NULLIF(`request_date`, ''), '%m/%d/%Y') DESC, `created_at` DESC");
        }
    }
}
