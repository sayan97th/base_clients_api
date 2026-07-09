<?php

namespace App\Jobs;

use App\Jobs\Concerns\ParsesLinkBuildingCsvDates;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Bulk-updates a fixed set of metric columns (Current Traffic, DR Formula, Current POC,
 * Current Price, etc.) on existing link_building_order_placements rows, matched by
 * Order ID against a reference CSV exported from the client's Partner Database sheet.
 *
 * Unlike ProcessLinkBuildingImportJob, this job never creates new rows and never touches
 * any column outside the caller-selected target_columns — it exists specifically so the
 * client's metrics owner (Marissa) can refresh just the metric fields on a monthly cadence
 * without re-uploading (and risking overwriting) the full order dataset.
 */
class ProcessLinkBuildingMetricsImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ParsesLinkBuildingCsvDates;

    public int $timeout = 900;
    public int $tries   = 1;

    private const CHUNK_SIZE = 500;

    /** Columns this job is ever allowed to write to. Kept in sync with the frontend column picker. */
    public const TARGET_COLUMNS = [
        'current_traffic',
        'dr_formula',
        'current_poc',
        'current_price',
        'dr_lbs',
        'posting_fee_lbs',
        'lb_tl_approval',
        'approval_date',
        'final_price',
    ];

    private const HEADER_MAP = [
        'order id'         => 'order_id',
        'request date'     => 'request_date',
        'dr lbs'           => 'dr_lbs',
        'posting fee lbs'  => 'posting_fee_lbs',
        'current traffic'  => 'current_traffic',
        'dr formula'       => 'dr_formula',
        'current poc'      => 'current_poc',
        'current price'    => 'current_price',
        'lb tl approval'   => 'lb_tl_approval',
        'approval date'    => 'approval_date',
        'final price'      => 'final_price',
    ];

    /** @param string[] $target_columns Subset of self::TARGET_COLUMNS to actually write. */
    public function __construct(
        private readonly string  $import_id,
        private readonly string  $file_path,
        private readonly int     $total_rows,
        private readonly array   $target_columns,
        private readonly ?string $date_from = null,
        private readonly ?string $date_to   = null,
    ) {}

    public function handle(): void
    {
        $this->saveProgress('processing', 0);

        $target_columns = array_values(array_intersect(self::TARGET_COLUMNS, $this->target_columns));

        if (empty($target_columns)) {
            $this->saveProgress('failed', 0, 0, 0, 0, [
                ['order_id' => '—', 'message' => 'No valid target columns were selected.'],
            ]);
            return;
        }

        $processed       = 0;
        $updated         = 0;
        $skipped         = 0;
        $errors          = [];
        $skipped_records = [];
        $chunk           = [];

        try {
            $full_path = Storage::path($this->file_path);
            $handle    = fopen($full_path, 'r');

            if ($handle === false) {
                throw new \RuntimeException('Could not open the uploaded file.');
            }

            // Strip UTF-8 BOM if present
            $bom = fread($handle, 3);
            if ($bom !== "\xEF\xBB\xBF") {
                rewind($handle);
            }

            $raw_headers = fgetcsv($handle);

            if ($raw_headers === false || empty($raw_headers)) {
                throw new \RuntimeException('The file appears to be empty or has no headers.');
            }

            $header_map = $this->buildHeaderMap($raw_headers, $target_columns);

            if (! in_array('order_id', $header_map, true)) {
                throw new \RuntimeException('The file must include an "Order ID" column so rows can be matched.');
            }

            while (($raw_row = fgetcsv($handle)) !== false) {
                if ($raw_row === [null]) {
                    continue;
                }

                $mapped   = $this->mapRow($raw_row, $header_map);
                $order_id = trim((string) ($mapped['order_id'] ?? ''));

                if ($order_id === '') {
                    $skipped++;
                    continue;
                }

                if (! $this->passesDateFilter((string) ($mapped['request_date'] ?? ''))) {
                    $skipped++;
                    if (count($skipped_records) < 100) {
                        $skipped_records[] = [
                            'order_id' => $order_id,
                            'reason'   => 'Request date (' . ($mapped['request_date'] ?? '') . ') is outside the last-year import window',
                        ];
                    }
                    continue;
                }

                unset($mapped['request_date']);
                $chunk[] = $mapped;

                if (count($chunk) >= self::CHUNK_SIZE) {
                    [$u, $s] = $this->processChunk($chunk, $target_columns, $errors, $skipped_records);
                    $updated   += $u;
                    $skipped   += $s;
                    $processed += count($chunk) - $s;
                    $chunk      = [];

                    $this->saveProgress('processing', $processed + $skipped, $updated, $skipped, $errors, $skipped_records);
                }
            }

            if (! empty($chunk)) {
                [$u, $s] = $this->processChunk($chunk, $target_columns, $errors, $skipped_records);
                $updated   += $u;
                $skipped   += $s;
                $processed += count($chunk) - $s;
            }

            fclose($handle);
            Storage::delete($this->file_path);

            $this->saveProgress('completed', $processed + $skipped, $updated, $skipped, $errors, $skipped_records);

        } catch (\Exception $e) {
            Log::error('LBO metrics import failed', [
                'import_id' => $this->import_id,
                'error'     => $e->getMessage(),
            ]);

            $this->saveProgress('failed', $processed + $skipped, $updated, $skipped, [
                ['order_id' => '—', 'message' => 'Metrics update failed: ' . $e->getMessage()],
            ], $skipped_records);
        }
    }

    /** @return array<int, string> Column index → db column name, restricted to order_id/request_date/$target_columns. */
    private function buildHeaderMap(array $raw_headers, array $target_columns): array
    {
        $allowed = [...$target_columns, 'order_id', 'request_date'];
        $map     = [];

        foreach ($raw_headers as $idx => $header) {
            $normalized = $this->normalizeHeader((string) $header);

            if (isset(self::HEADER_MAP[$normalized])) {
                $db_col = self::HEADER_MAP[$normalized];

                if (in_array($db_col, $allowed, true) && ! in_array($idx, array_keys($map), true)) {
                    $map[$idx] = $db_col;
                }
            }
        }

        return $map;
    }

    private function mapRow(array $raw_row, array $header_map): array
    {
        $mapped = [];

        foreach ($header_map as $col_idx => $db_col) {
            $raw_value       = (string) ($raw_row[$col_idx] ?? '');
            $mapped[$db_col] = trim($raw_value);
        }

        return $mapped;
    }

    /**
     * Updates only rows whose order_id already exists — unmatched order_ids are recorded
     * as skipped (this job intentionally never creates new placements).
     *
     * @return array{0:int,1:int} [$updated_count, $skipped_count]
     */
    private function processChunk(array $chunk, array $target_columns, array &$errors, array &$skipped_records): array
    {
        $now = now()->toDateTimeString();

        $chunk_order_ids = array_filter(array_column($chunk, 'order_id'), fn ($v) => $v !== '');

        $existing_set = DB::table('link_building_order_placements')
            ->whereIn('order_id', $chunk_order_ids)
            ->pluck('order_id')
            ->flip()
            ->all();

        $chunk_updated = 0;
        $chunk_skipped = 0;

        DB::transaction(function () use ($chunk, $target_columns, $existing_set, $now, &$chunk_updated, &$chunk_skipped, &$errors, &$skipped_records) {
            foreach ($chunk as $row) {
                $order_id = $row['order_id'];

                if (! isset($existing_set[$order_id])) {
                    $chunk_skipped++;
                    if (count($skipped_records) < 100) {
                        $skipped_records[] = [
                            'order_id' => $order_id,
                            'reason'   => 'Order ID not found in the system — no matching row to update',
                        ];
                    }
                    continue;
                }

                $update_values = [];
                foreach ($target_columns as $col) {
                    if (array_key_exists($col, $row)) {
                        $update_values[$col] = $row[$col] === '' ? null : $row[$col];
                    }
                }

                if (empty($update_values)) {
                    $chunk_skipped++;
                    continue;
                }

                try {
                    $update_values['updated_at'] = $now;
                    DB::table('link_building_order_placements')
                        ->where('order_id', $order_id)
                        ->update($update_values);
                    $chunk_updated++;
                } catch (\Exception $e) {
                    $errors[] = ['order_id' => $order_id, 'message' => $e->getMessage()];
                }
            }
        });

        return [$chunk_updated, $chunk_skipped];
    }

    private function saveProgress(
        string $status,
        int $processed = 0,
        int $updated = 0,
        int $skipped = 0,
        array $errors = [],
        array $skipped_records = []
    ): void {
        Cache::put("lbo_import_{$this->import_id}", [
            'status'          => $status,
            'total'           => $this->total_rows,
            'processed'       => $processed,
            'created'         => 0,
            'updated'         => $updated,
            'skipped'         => $skipped,
            'assigned'        => 0,
            'errors'          => array_slice($errors, 0, 50),
            'skipped_records' => array_slice($skipped_records, 0, 100),
        ], now()->addHours(2));
    }
}
