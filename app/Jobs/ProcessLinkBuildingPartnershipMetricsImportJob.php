<?php

namespace App\Jobs;

use App\Jobs\Concerns\ParsesLinkBuildingCsvDates;
use App\Models\LinkBuildingOrderPlacement;
use App\Models\LinkBuildingPartnerMetric;
use App\Services\LinkBuildingPartnerMetricsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Bulk metrics update matched by Partnership, replacing the VLOOKUP workflow of the
 * BASE Google Sheet. It runs in two steps:
 *
 * 1. Reference list: every domain of the uploaded file is saved to
 *    link_building_partner_metrics with the columns the file provides (DR, traffic,
 *    POC, price). When the file has POC or price columns and mark_missing_as_removed
 *    is on, it is treated as the complete partner list, so domains missing from it
 *    leave the list and their orders show SITE REMOVED.
 *
 * 2. Link Table: every row with a Partnership whose Request Date is inside the
 *    date range gets the selected columns from the reference list. Rows outside the
 *    range keep their values, like the rows the metrics owner used to freeze by
 *    pasting values over the formulas. A domain without metrics keeps its last DR
 *    and traffic, and is listed in the skipped details so it can be reviewed.
 *
 * Progress is stored under the same cache key as the other imports, so it is
 * polled with GET /import-status/{import_id}.
 */
class ProcessLinkBuildingPartnershipMetricsImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ParsesLinkBuildingCsvDates;

    public int $timeout = 900;
    public int $tries   = 1;

    private const CHUNK_SIZE = 500;

    private const MAX_SKIPPED_RECORDS = 100;

    /**
     * Accepted (normalized) header names for each reference field, so the exported
     * metrics and partner database sheets can be uploaded without renaming columns.
     * When several columns match a field, the leftmost one is used.
     */
    private const HEADER_ALIASES = [
        'domain' => [
            'partnership', 'partnerships', 'partnership domain', 'domain', 'domains',
            'website', 'website name', 'site', 'site name', 'target', 'url',
        ],
        'dr' => [
            'dr', 'dr formula', 'current dr', 'domain rating', 'ahrefs dr', 'ahrefs domain rating',
        ],
        'traffic' => [
            'traffic', 'current traffic', 'organic traffic', 'monthly traffic',
            'ahrefs traffic', 'ahrefs organic traffic',
        ],
        'current_poc' => [
            'poc', 'current poc', 'point of contact', 'publisher', 'current publisher', 'posted by',
        ],
        'current_price' => [
            'price', 'current price', 'posting fee', 'current posting fee', 'fee', 'cost', 'publisher price',
        ],
    ];

    /** Maximum stored length of each reference column, matching the migration. */
    private const COLUMN_LENGTHS = [
        'domain'        => 255,
        'dr'            => 50,
        'traffic'       => 50,
        'current_poc'   => 255,
        'current_price' => 50,
    ];

    /**
     * @param string[] $target_columns Subset of LinkBuildingPartnerMetricsService::PLACEMENT_COLUMNS to write on the Link Table.
     */
    public function __construct(
        private readonly string  $import_id,
        private readonly string  $file_path,
        private readonly array   $target_columns,
        private readonly bool    $mark_missing_as_removed = true,
        private readonly ?string $date_from = null,
        private readonly ?string $date_to   = null,
    ) {}

    public function handle(LinkBuildingPartnerMetricsService $metrics_service): void
    {
        $summary = [
            'total'             => 0,
            'processed'         => 0,
            'updated'           => 0,
            'unchanged'         => 0,
            'skipped'           => 0,
            'site_removed'      => 0,
            'out_of_range'      => 0,
            'reference_rows'    => 0,
            'reference_columns' => [],
            'errors'            => [],
            'skipped_records'   => [],
        ];

        $this->saveProgress('processing', $summary);

        $target_columns = array_values(array_intersect(LinkBuildingPartnerMetricsService::PLACEMENT_COLUMNS, $this->target_columns));

        try {
            if (empty($target_columns)) {
                throw new \RuntimeException('No valid target columns were selected.');
            }

            [$entries, $file_columns] = $this->readReferenceFile();

            $summary['reference_rows']    = count($entries);
            $summary['reference_columns'] = $file_columns;
            $this->saveProgress('processing', $summary);

            $this->syncReferenceList($entries, $file_columns);

            $this->applyToPlacements($metrics_service, $target_columns, $summary);

            Storage::delete($this->file_path);

            $this->saveProgress('completed', $summary);
        } catch (\Throwable $e) {
            Log::error('LBO partnership metrics import failed', [
                'import_id' => $this->import_id,
                'error'     => $e->getMessage(),
            ]);

            Storage::delete($this->file_path);

            $summary['errors'] = [
                ['order_id' => '-', 'message' => 'Metrics update failed: ' . $e->getMessage()],
            ];
            $this->saveProgress('failed', $summary);
        }
    }

    // ── Step 1: reference list ─────────────────────────────────────────────────

    /**
     * Reads the uploaded file into one entry per domain. A domain listed twice keeps
     * its last row, like pasting a newer value over an older one.
     *
     * @return array{0: array<string, array<string, string|null>>, 1: string[]}
     *         [domain_key => entry, reference columns found in the file]
     */
    private function readReferenceFile(): array
    {
        $handle = fopen(Storage::path($this->file_path), 'r');

        if ($handle === false) {
            throw new \RuntimeException('Could not open the uploaded file.');
        }

        try {
            // Strip UTF-8 BOM if present
            if (fread($handle, 3) !== "\xEF\xBB\xBF") {
                rewind($handle);
            }

            $raw_headers = fgetcsv($handle);

            if ($raw_headers === false || empty($raw_headers)) {
                throw new \RuntimeException('The file appears to be empty or has no headers.');
            }

            $header_map = $this->buildHeaderMap($raw_headers);

            if (! isset($header_map['domain'])) {
                throw new \RuntimeException('The file must include a "Partnership" (or "Domain" / "Website") column so rows can be matched.');
            }

            $file_columns = array_values(array_diff(array_keys($header_map), ['domain']));

            if (empty($file_columns)) {
                throw new \RuntimeException('The file must include at least one of these columns: DR, Traffic, Current POC, Current Price.');
            }

            $entries = [];

            while (($raw_row = fgetcsv($handle)) !== false) {
                if ($raw_row === [null]) {
                    continue;
                }

                $domain     = trim((string) ($raw_row[$header_map['domain']] ?? ''));
                $domain_key = LinkBuildingPartnerMetric::normalizeDomainKey($domain);

                if ($domain_key === null) {
                    continue;
                }

                $entry = ['domain' => mb_substr($domain, 0, self::COLUMN_LENGTHS['domain'])];

                foreach ($file_columns as $column) {
                    $value          = trim((string) ($raw_row[$header_map[$column]] ?? ''));
                    $entry[$column] = $value === '' ? null : mb_substr($value, 0, self::COLUMN_LENGTHS[$column]);
                }

                $entries[$domain_key] = $entry;
            }
        } finally {
            fclose($handle);
        }

        if (empty($entries)) {
            throw new \RuntimeException('The file has no rows with a partnership domain.');
        }

        return [$entries, $file_columns];
    }

    /** @return array<string, int> Reference field => column index. */
    private function buildHeaderMap(array $raw_headers): array
    {
        $map = [];

        foreach ($raw_headers as $index => $header) {
            $normalized = $this->normalizeHeader((string) $header);

            foreach (self::HEADER_ALIASES as $field => $aliases) {
                if (! isset($map[$field]) && in_array($normalized, $aliases, true)) {
                    $map[$field] = $index;
                    break;
                }
            }
        }

        return $map;
    }

    /**
     * Saves the file domains to the reference list. Only the columns the file
     * provides are written, so a metrics file never clears the POC and price
     * of a domain, and a partner file never clears its DR and traffic.
     *
     * @param array<string, array<string, string|null>> $entries
     * @param string[] $file_columns
     */
    private function syncReferenceList(array $entries, array $file_columns): void
    {
        $now              = now()->toDateTimeString();
        $has_metrics      = ! empty(array_intersect(['dr', 'traffic'], $file_columns));
        $has_partner_data = ! empty(array_intersect(['current_poc', 'current_price'], $file_columns));

        $update_columns = ['domain', ...$file_columns, 'updated_at'];
        if ($has_metrics) {
            $update_columns[] = 'metrics_synced_at';
        }
        if ($has_partner_data) {
            $update_columns[] = 'partner_synced_at';
        }

        DB::transaction(function () use ($entries, $now, $has_metrics, $has_partner_data, $update_columns) {
            // The file is the complete partner list: domains missing from it were removed
            // (low metrics or the publisher lost access), so their orders show SITE REMOVED.
            // Every domain leaves the list first, and the upsert below puts back the ones
            // in the file, all in one transaction so readers never see an empty list.
            if ($has_partner_data && $this->mark_missing_as_removed) {
                DB::table('link_building_partner_metrics')
                    ->whereNotNull('partner_synced_at')
                    ->update([
                        'current_poc'       => null,
                        'current_price'     => null,
                        'partner_synced_at' => null,
                        'updated_at'        => $now,
                    ]);
            }

            foreach (array_chunk($entries, self::CHUNK_SIZE, true) as $chunk) {
                $rows = [];

                foreach ($chunk as $domain_key => $entry) {
                    $row = ['domain_key' => $domain_key, ...$entry, 'created_at' => $now, 'updated_at' => $now];

                    if ($has_metrics) {
                        $row['metrics_synced_at'] = $now;
                    }
                    if ($has_partner_data) {
                        $row['partner_synced_at'] = $now;
                    }

                    $rows[] = $row;
                }

                DB::table('link_building_partner_metrics')->upsert($rows, ['domain_key'], $update_columns);
            }
        });
    }

    // ── Step 2: Link Table ─────────────────────────────────────────────────────

    /**
     * Writes the reference values on every Link Table row inside the date range,
     * only touching the cells whose value actually changes.
     */
    private function applyToPlacements(LinkBuildingPartnerMetricsService $metrics_service, array $target_columns, array &$summary): void
    {
        /** @var Collection<string, LinkBuildingPartnerMetric> $references */
        $references            = LinkBuildingPartnerMetric::all()->keyBy('domain_key');
        $has_metrics_reference = $metrics_service->hasMetricsReference();
        $has_partner_list      = $metrics_service->hasPartnerList();
        $targets_metrics       = ! empty(array_intersect(LinkBuildingPartnerMetricsService::METRIC_COLUMNS, $target_columns));

        $query = DB::table('link_building_order_placements')
            ->whereNotNull('partnership')
            ->where('partnership', '!=', '');

        $summary['total'] = (clone $query)->count();
        $this->saveProgress('processing', $summary);

        $query
            ->select(['id', 'order_id', 'partnership', 'request_date', 'cell_formulas', ...$target_columns])
            ->orderBy('id')
            ->chunk(self::CHUNK_SIZE, function ($placements) use ($metrics_service, $target_columns, $references, $has_metrics_reference, $has_partner_list, $targets_metrics, &$summary) {
                $now = now()->toDateTimeString();

                DB::transaction(function () use ($placements, $metrics_service, $target_columns, $references, $has_metrics_reference, $has_partner_list, $targets_metrics, $now, &$summary) {
                    foreach ($placements as $placement) {
                        $summary['processed']++;

                        if (! $this->passesDateFilter((string) ($placement->request_date ?? ''))) {
                            $summary['out_of_range']++;
                            continue;
                        }

                        $reference = $references->get(LinkBuildingPartnerMetric::normalizeDomainKey($placement->partnership));

                        $values = $metrics_service->resolvePlacementValues(
                            $reference,
                            $target_columns,
                            $has_metrics_reference,
                            $has_partner_list,
                            clear_missing_metrics: false,
                        );

                        if ($targets_metrics && ! $reference?->hasMetrics()) {
                            $this->addSkippedRecord($summary, $placement, 'No DR or traffic found for partnership "' . $placement->partnership . '", metrics kept as they were');
                        }

                        if (in_array(LinkBuildingPartnerMetric::SITE_REMOVED, $values, true)) {
                            $summary['site_removed']++;
                        }

                        if (empty($values)) {
                            $summary['skipped']++;
                            continue;
                        }

                        $changed = array_filter(
                            $values,
                            fn ($value, $column) => (string) ($placement->{$column} ?? '') !== (string) ($value ?? ''),
                            ARRAY_FILTER_USE_BOTH
                        );

                        if (empty($changed)) {
                            $summary['unchanged']++;
                            continue;
                        }

                        try {
                            $changed['updated_at'] = $now;
                            // Updated metrics replace the spreadsheet formulas of those cells.
                            $remove_formulas = LinkBuildingOrderPlacement::removeCellFormulasExpression(array_keys($changed));
                            if ($remove_formulas && $placement->cell_formulas !== null) {
                                $changed['cell_formulas'] = $remove_formulas;
                            }

                            DB::table('link_building_order_placements')
                                ->where('id', $placement->id)
                                ->update($changed);

                            $summary['updated']++;
                        } catch (\Exception $e) {
                            if (count($summary['errors']) < 50) {
                                $summary['errors'][] = ['order_id' => $placement->order_id ?? $placement->id, 'message' => $e->getMessage()];
                            }
                        }
                    }
                });

                $this->saveProgress('processing', $summary);
            });
    }

    private function addSkippedRecord(array &$summary, object $placement, string $reason): void
    {
        if (count($summary['skipped_records']) >= self::MAX_SKIPPED_RECORDS) {
            return;
        }

        $summary['skipped_records'][] = [
            'order_id' => $placement->order_id ?? $placement->id,
            'reason'   => $reason,
        ];
    }

    private function saveProgress(string $status, array $summary): void
    {
        Cache::put("lbo_import_{$this->import_id}", [
            'status'            => $status,
            'total'             => $summary['total'],
            'processed'         => $summary['processed'],
            'created'           => 0,
            'updated'           => $summary['updated'],
            'unchanged'         => $summary['unchanged'],
            'skipped'           => $summary['skipped'],
            'site_removed'      => $summary['site_removed'],
            'out_of_range'      => $summary['out_of_range'],
            'reference_rows'    => $summary['reference_rows'],
            'reference_columns' => $summary['reference_columns'],
            'assigned'          => 0,
            'errors'            => array_slice($summary['errors'], 0, 50),
            'skipped_records'   => array_slice($summary['skipped_records'], 0, self::MAX_SKIPPED_RECORDS),
        ], now()->addHours(2));
    }
}
