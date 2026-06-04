<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProcessLinkBuildingImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;
    public int $tries   = 1;

    private const CHUNK_SIZE = 500;

    private const HEADER_MAP = [
        'order id'                       => 'order_id',
        'team specific link id'          => 'team_specific_link_id',
        'link type'                      => 'link_type',
        'client'                         => 'client',
        'keyword'                        => 'keyword',
        'landing page'                   => 'landing_page',
        'exact match'                    => 'exact_match',
        'notes'                          => 'notes',
        'request date'                   => 'request_date',
        'estimated delivery date'        => 'estimated_delivery_date',
        'estimated turnaround time days' => 'estimated_turnaround_days',
        'estimated turnaround days'      => 'estimated_turnaround_days',
        'turnaround days'                => 'estimated_turnaround_days',
        'link builder'                   => 'link_builder',
        'pen name'                       => 'pen_name',
        'partnership'                    => 'partnership',
        'article title'                  => 'article_title',
        'article'                        => 'article',
        'status'                         => 'status',
        'live link'                      => 'live_link',
        'live link date'                 => 'live_link_date',
        'dr lbs'                         => 'dr_lbs',
        'posting fee lbs'                => 'posting_fee_lbs',
        'current traffic'                => 'current_traffic',
        'dr formula'                     => 'dr_formula',
        'current poc'                    => 'current_poc',
        'current price'                  => 'current_price',
        'lb tl approval'                 => 'lb_tl_approval',
        'approval date'                  => 'approval_date',
        'final price'                    => 'final_price',
    ];

    private const IMPORTABLE_COLUMNS = [
        'order_id', 'team_specific_link_id', 'link_type', 'client', 'keyword',
        'landing_page', 'exact_match', 'notes', 'request_date',
        'estimated_delivery_date', 'estimated_turnaround_days', 'link_builder',
        'pen_name', 'partnership', 'article_title', 'article', 'status',
        'live_link', 'live_link_date', 'dr_lbs', 'posting_fee_lbs',
        'current_traffic', 'dr_formula', 'current_poc', 'current_price',
        'lb_tl_approval', 'approval_date', 'final_price',
    ];

    private const URL_COLUMNS = ['landing_page', 'partnership', 'article', 'live_link'];

    public function __construct(
        private readonly string $import_id,
        private readonly string $file_path,
        private readonly int    $total_rows,
    ) {}

    public function handle(): void
    {
        $this->saveProgress('processing', 0);

        $processed = 0;
        $created   = 0;
        $updated   = 0;
        $skipped   = 0;
        $errors    = [];
        $chunk     = [];

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

            $header_map = $this->buildHeaderMap($raw_headers);

            while (($raw_row = fgetcsv($handle)) !== false) {
                if ($raw_row === [null]) {
                    continue;
                }

                $mapped   = $this->mapRow($raw_row, $header_map);
                $order_id = trim($mapped['order_id'] ?? '');

                if ($order_id === '') {
                    $skipped++;
                    continue;
                }

                $chunk[] = $mapped;

                if (count($chunk) >= self::CHUNK_SIZE) {
                    [$c, $u] = $this->processChunk($chunk, $errors);
                    $created   += $c;
                    $updated   += $u;
                    $processed += count($chunk);
                    $chunk      = [];

                    $this->saveProgress('processing', $processed + $skipped, $created, $updated, $skipped, $errors);
                }
            }

            if (!empty($chunk)) {
                [$c, $u] = $this->processChunk($chunk, $errors);
                $created   += $c;
                $updated   += $u;
                $processed += count($chunk);
            }

            fclose($handle);
            Storage::delete($this->file_path);

            $this->saveProgress('completed', $processed + $skipped, $created, $updated, $skipped, $errors);

        } catch (\Exception $e) {
            Log::error('LBO CSV import failed', [
                'import_id' => $this->import_id,
                'error'     => $e->getMessage(),
            ]);

            $this->saveProgress('failed', $processed + $skipped, $created, $updated, $skipped, [
                ['order_id' => '—', 'message' => 'Import failed: ' . $e->getMessage()],
            ]);
        }
    }

    private function buildHeaderMap(array $raw_headers): array
    {
        $map = [];

        foreach ($raw_headers as $idx => $header) {
            $normalized = $this->normalizeHeader((string) $header);

            if (isset(self::HEADER_MAP[$normalized])) {
                $db_col = self::HEADER_MAP[$normalized];

                if (in_array($db_col, self::IMPORTABLE_COLUMNS, true) && !in_array($idx, array_keys($map), true)) {
                    $map[$idx] = $db_col;
                }
            }
        }

        return $map;
    }

    private function normalizeHeader(string $header): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9\s]/', ' ', $header);
        $clean = preg_replace('/\s+/', ' ', $clean);

        return strtolower(trim($clean));
    }

    private function mapRow(array $raw_row, array $header_map): array
    {
        $mapped = [];

        foreach ($header_map as $col_idx => $db_col) {
            $raw_value        = $raw_row[$col_idx] ?? '';
            $mapped[$db_col]  = $this->sanitizeValue($db_col, (string) $raw_value);
        }

        return $mapped;
    }

    private function sanitizeValue(string $db_col, string $raw): string|int|null
    {
        $value = trim($raw);

        if ($db_col === 'exact_match') {
            return strtolower($value) === 'yes' ? 1 : 0;
        }

        if ($db_col === 'status') {
            return $value === '' ? 'New Request' : $this->normalizeStatus($value);
        }

        if (in_array($db_col, self::URL_COLUMNS, true)) {
            if ($value === '') {
                return null;
            }

            return preg_match('#^https?://#i', $value) ? $value : 'https://' . $value;
        }

        return $value === '' ? null : $value;
    }

    private function normalizeStatus(string $raw): string
    {
        $lower = strtolower(preg_replace('/[^a-zA-Z\s]/', ' ', $raw));
        $lower = trim(preg_replace('/\s+/', ' ', $lower));

        if (str_contains($lower, 'quality')) return 'Quality Control';
        if (str_contains($lower, 'live'))    return 'Live';
        if (str_contains($lower, 'cancel'))  return 'Cancelled';
        if (str_contains($lower, 'review'))  return 'Reviewing';
        if (str_contains($lower, 'ordered')) return 'Ordered';
        if (str_contains($lower, 'order'))   return 'Ordered';
        if (str_contains($lower, 'pending')) return 'Pending';

        return 'New Request';
    }

    /**
     * Bulk-upserts a chunk via a single SQL statement.
     * Returns [created_count, updated_count].
     */
    private function processChunk(array $chunk, array &$errors): array
    {
        $now           = now()->toDateTimeString();
        $chunk_order_ids = array_filter(
            array_column($chunk, 'order_id'),
            fn ($v) => $v !== null && $v !== ''
        );

        $existing_set = DB::table('link_building_order_placements')
            ->whereIn('order_id', $chunk_order_ids)
            ->pluck('order_id')
            ->flip()
            ->all();

        $rows          = [];
        $chunk_created = 0;
        $chunk_updated = 0;

        foreach ($chunk as $row) {
            $order_id = trim($row['order_id'] ?? '');

            if ($order_id === '') {
                continue;
            }

            $row['updated_at'] = $now;

            if (!isset($existing_set[$order_id])) {
                $row['id']         = Str::uuid()->toString();
                $row['created_at'] = $now;
                $chunk_created++;
            } else {
                $chunk_updated++;
            }

            $rows[] = $row;
        }

        if (empty($rows)) {
            return [0, 0];
        }

        $update_columns = array_values(array_diff(
            array_keys($rows[0]),
            ['id', 'order_id', 'created_at']
        ));

        try {
            DB::table('link_building_order_placements')->upsert(
                $rows,
                ['order_id'],
                $update_columns
            );
        } catch (\Exception $e) {
            // Fallback: process row-by-row on bulk upsert failure
            Log::warning('LBO import bulk upsert failed, falling back to row-by-row', [
                'import_id' => $this->import_id,
                'error'     => $e->getMessage(),
            ]);

            $chunk_created = 0;
            $chunk_updated = 0;

            foreach ($rows as $row) {
                try {
                    $order_id = $row['order_id'];
                    unset($row['id'], $row['created_at'], $row['updated_at']);

                    $existing = DB::table('link_building_order_placements')
                        ->where('order_id', $order_id)
                        ->first();

                    if ($existing) {
                        DB::table('link_building_order_placements')
                            ->where('order_id', $order_id)
                            ->update(array_merge($row, ['updated_at' => $now]));
                        $chunk_updated++;
                    } else {
                        DB::table('link_building_order_placements')->insert(
                            array_merge($row, [
                                'id'         => Str::uuid()->toString(),
                                'order_id'   => $order_id,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ])
                        );
                        $chunk_created++;
                    }
                } catch (\Exception $row_e) {
                    $errors[] = [
                        'order_id' => $row['order_id'] ?? '?',
                        'message'  => $row_e->getMessage(),
                    ];
                }
            }
        }

        return [$chunk_created, $chunk_updated];
    }

    private function saveProgress(
        string $status,
        int $processed = 0,
        int $created = 0,
        int $updated = 0,
        int $skipped = 0,
        array $errors = []
    ): void {
        Cache::put("lbo_import_{$this->import_id}", [
            'status'    => $status,
            'total'     => $this->total_rows,
            'processed' => $processed,
            'created'   => $created,
            'updated'   => $updated,
            'skipped'   => $skipped,
            'errors'    => array_slice($errors, 0, 50),
        ], now()->addHours(2));
    }
}
