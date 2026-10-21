<?php

namespace App\Http\Controllers\Admin\LinkBuilding;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessLinkBuildingPartnershipMetricsImportJob;
use App\Models\LinkBuildingPartnerMetric;
use App\Services\LinkBuildingPartnerMetricsService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Partner metrics reference list of the admin "Link Building Orders" table, the
 * portal version of the "SEO Metrics" and "Current POC" tabs of the BASE Google
 * Sheet. The Link Table looks up each row's Partnership here.
 */
class LinkBuildingPartnerMetricsController extends Controller
{
    /** Default Request Date window of the bulk update: rows older than this keep their values. */
    private const DEFAULT_WINDOW_MONTHS = 2;

    /**
     * POST /api/admin/link-building-orders/partnership-metrics-import
     *
     * Uploads the latest domain metrics and/or partner list (domain, DR, traffic,
     * POC, price) and updates the selected columns of every Link Table row whose
     * Partnership matches, instead of matching by Order ID. Returns an import_id
     * pollable via GET /import-status/{import_id}.
     *
     * Request body:
     *   file                     (file, required)     CSV/TXT with a Partnership (or Domain/Website) column
     *   target_columns           (string[], required)  subset of LinkBuildingPartnerMetricsService::PLACEMENT_COLUMNS
     *   date_from                (string MM/DD/YYYY)   lower bound for request_date, defaults to two months ago
     *   date_to                  (string MM/DD/YYYY)   upper bound for request_date, no default
     *   mark_missing_as_removed  (bool, default true)  when the file has POC or price columns, partnerships
     *                                                  missing from it are marked SITE REMOVED
     */
    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file'                    => ['required', 'file', 'mimes:csv,txt', 'max:51200'],
            'target_columns'          => ['required', 'array', 'min:1'],
            'target_columns.*'        => ['string', Rule::in(LinkBuildingPartnerMetricsService::PLACEMENT_COLUMNS)],
            'date_from'               => ['sometimes', 'nullable', 'date_format:m/d/Y'],
            'date_to'                 => ['sometimes', 'nullable', 'date_format:m/d/Y'],
            'mark_missing_as_removed' => ['sometimes', 'boolean'],
        ]);

        $file           = $request->file('file');
        $import_id      = Str::uuid()->toString();
        $target_columns = array_values(array_unique($request->input('target_columns')));

        $date_from = filled($request->input('date_from'))
            ? $request->input('date_from')
            : Carbon::now()->subMonths(self::DEFAULT_WINDOW_MONTHS)->format('m/d/Y');

        $date_to = filled($request->input('date_to'))
            ? $request->input('date_to')
            : null;

        $mark_missing_as_removed = filter_var($request->input('mark_missing_as_removed', true), FILTER_VALIDATE_BOOLEAN);

        $stored_path = $file->storeAs(
            'imports/link-building',
            $import_id . '_partnership_metrics_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file->getClientOriginalName())
        );

        Cache::put("lbo_import_{$import_id}", [
            'status'    => 'queued',
            'total'     => 0,
            'processed' => 0,
            'created'   => 0,
            'updated'   => 0,
            'skipped'   => 0,
            'errors'    => [],
        ], now()->addHours(2));

        ProcessLinkBuildingPartnershipMetricsImportJob::dispatch(
            $import_id,
            $stored_path,
            $target_columns,
            $mark_missing_as_removed,
            $date_from,
            $date_to,
        );

        return response()->json([
            'message'   => 'Partnership metrics update queued successfully.',
            'import_id' => $import_id,
            'total'     => 0,
        ], 202);
    }

    /**
     * GET /api/admin/link-building-orders/partnership-metrics/summary
     *
     * Size and freshness of the reference list, shown in the update dialog so the
     * admin knows when the metrics and the partner list were last uploaded.
     */
    public function summary(): JsonResponse
    {
        $last_metrics_sync = LinkBuildingPartnerMetric::max('metrics_synced_at');
        $last_partner_sync = LinkBuildingPartnerMetric::max('partner_synced_at');

        return response()->json([
            'data' => [
                'total_domains'     => LinkBuildingPartnerMetric::count(),
                'metrics_domains'   => LinkBuildingPartnerMetric::whereNotNull('metrics_synced_at')->count(),
                'listed_partners'   => LinkBuildingPartnerMetric::whereNotNull('partner_synced_at')->count(),
                'last_metrics_sync' => $last_metrics_sync ? Carbon::parse($last_metrics_sync)->toIso8601String() : null,
                'last_partner_sync' => $last_partner_sync ? Carbon::parse($last_partner_sync)->toIso8601String() : null,
            ],
        ]);
    }
}
