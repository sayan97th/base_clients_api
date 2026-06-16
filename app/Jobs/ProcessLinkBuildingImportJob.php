<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\User;
use Carbon\Carbon;
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
        private readonly string  $import_id,
        private readonly string  $file_path,
        private readonly int     $total_rows,
        private readonly ?string $date_from = null,
        private readonly ?string $date_to   = null,
        private readonly string  $link_type_filter = 'external_only',
    ) {}

    public function handle(): void
    {
        $this->saveProgress('processing', 0);

        // Pre-load company name → user_id map for client auto-assignment.
        $client_company_map = $this->loadClientCompanyMap();

        // Pre-load admin user name → user_id map for link builder auto-assignment.
        $admin_user_name_map = $this->loadAdminUserNameMap();

        $processed       = 0;
        $created         = 0;
        $updated         = 0;
        $skipped         = 0;
        $assigned        = 0;
        $errors          = [];
        $skipped_records = []; // per-row skip log: [['order_id' => ..., 'reason' => ...]]
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

                if (! $this->passesLinkTypeFilter((string) ($mapped['link_type'] ?? ''))) {
                    $skipped++;
                    if (count($skipped_records) < 100) {
                        $skipped_records[] = [
                            'order_id' => $order_id,
                            'reason'   => 'Link type not included in filter: "' . ($mapped['link_type'] ?? '') . '"',
                        ];
                    }
                    continue;
                }

                if (! $this->passesDateFilter((string) ($mapped['request_date'] ?? ''))) {
                    $skipped++;
                    if (count($skipped_records) < 100) {
                        $req_date = (string) ($mapped['request_date'] ?? '');
                        $bounds   = [];
                        if ($this->date_from !== null) {
                            $bounds[] = 'after ' . $this->date_from;
                        }
                        if ($this->date_to !== null) {
                            $bounds[] = 'before ' . $this->date_to;
                        }
                        $range_desc = $bounds !== [] ? ' (must be ' . implode(' and ', $bounds) . ')' : '';
                        $skipped_records[] = [
                            'order_id' => $order_id,
                            'reason'   => 'Request date (' . $req_date . ') is outside the import filter range' . $range_desc,
                        ];
                    }
                    continue;
                }

                // Resolve client user from the "client" column matched against User.company.
                $client_value = strtolower(trim((string) ($mapped['client'] ?? '')));
                if ($client_value !== '' && isset($client_company_map[$client_value])) {
                    $mapped['_resolved_user_id'] = $client_company_map[$client_value];
                }

                // Resolve admin user (Assigned To) from the "link_builder" column.
                $link_builder_value = trim((string) ($mapped['link_builder'] ?? ''));
                if ($link_builder_value !== '') {
                    $resolved_admin_id = $this->resolveAdminUserId($link_builder_value, $admin_user_name_map);
                    if ($resolved_admin_id !== null) {
                        $mapped['_resolved_admin_user_id'] = $resolved_admin_id;
                    }
                }

                $chunk[] = $mapped;

                if (count($chunk) >= self::CHUNK_SIZE) {
                    [$c, $u, $a] = $this->processChunk($chunk, $errors);
                    $created   += $c;
                    $updated   += $u;
                    $assigned  += $a;
                    $processed += count($chunk);
                    $chunk      = [];

                    $this->saveProgress('processing', $processed + $skipped, $created, $updated, $skipped, $assigned, $errors, $skipped_records);
                }
            }

            if (!empty($chunk)) {
                [$c, $u, $a] = $this->processChunk($chunk, $errors);
                $created   += $c;
                $updated   += $u;
                $assigned  += $a;
                $processed += count($chunk);
            }

            fclose($handle);
            Storage::delete($this->file_path);

            $this->saveProgress('completed', $processed + $skipped, $created, $updated, $skipped, $assigned, $errors, $skipped_records);

        } catch (\Exception $e) {
            Log::error('LBO CSV import failed', [
                'import_id' => $this->import_id,
                'error'     => $e->getMessage(),
            ]);

            $this->saveProgress('failed', $processed + $skipped, $created, $updated, $skipped, $assigned, [
                ['order_id' => '—', 'message' => 'Import failed: ' . $e->getMessage()],
            ], $skipped_records);
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

        // Normalize any date column to MM/DD/YYYY for consistent storage regardless
        // of the source format (e.g. "4/15/2026", "04/15/26 12:38 PM", etc.).
        if (in_array($db_col, ['request_date', 'estimated_delivery_date', 'live_link_date', 'approval_date'], true)) {
            if ($value === '') {
                return null;
            }
            $parsed = $this->parseDateFlexible($value);
            return $parsed !== null ? $parsed->format('m/d/Y') : $value;
        }

        if (in_array($db_col, self::URL_COLUMNS, true)) {
            if ($value === '') {
                return null;
            }

            // If value already has a URL scheme, keep it (truncated to 2000 chars for safety).
            if (preg_match('#^https?://#i', $value)) {
                return mb_substr($value, 0, 2000);
            }

            // Values containing spaces are plain text/notes, not URLs — store as-is
            // rather than corrupting them with a "https://" prefix.
            if (str_contains($value, ' ')) {
                return mb_substr($value, 0, 2000);
            }

            return 'https://' . mb_substr($value, 0, 1993); // 1993 + 7 = 2000
        }

        return $value === '' ? null : $value;
    }

    private function normalizeStatus(string $raw): string
    {
        $lower = strtolower(preg_replace('/[^a-zA-Z\s]/', ' ', $raw));
        $lower = trim(preg_replace('/\s+/', ' ', $lower));

        if (str_contains($lower, 'quality'))       return 'Quality Control';
        if (str_contains($lower, 'live'))          return 'Live';
        if (str_contains($lower, 'cancel'))        return 'Cancelled';
        if (str_contains($lower, 'review'))        return 'Reviewing';
        if (str_contains($lower, 'not approved'))  return 'Not Approved'; // before 'approved'
        if (str_contains($lower, 'approved'))      return 'Approved';
        if (str_contains($lower, 'ordered'))       return 'Ordered';
        if (str_contains($lower, 'order'))         return 'Ordered';
        if (str_contains($lower, 'pending'))       return 'Pending';
        if (str_contains($lower, 'partnership'))   return 'Partnership Check';
        if (str_contains($lower, 'ready'))         return 'Ready';
        if (str_contains($lower, 'rejected'))      return 'Rejected';
        if (str_contains($lower, 'scheduled'))     return 'Scheduled';

        return 'New Request';
    }

    /**
     * Bulk-upserts a chunk via a single SQL statement.
     * Returns [created_count, updated_count].
     *
     * After the main upsert, any rows where the CSV "client" column matched a client
     * account by company name receive a separate user_id UPDATE. This two-phase
     * approach preserves manually-set assignments for rows where no company match
     * was found in the CSV.
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

        $rows                      = [];
        $user_id_assignments       = []; // order_id => user_id for company-matched rows
        $admin_user_id_assignments = []; // order_id => admin user_id for link-builder-matched rows
        $chunk_created             = 0;
        $chunk_updated             = 0;

        foreach ($chunk as $row) {
            $order_id = trim($row['order_id'] ?? '');

            if ($order_id === '') {
                continue;
            }

            // Extract the internally-resolved user_id (not a real CSV column).
            $resolved_user_id = $row['_resolved_user_id'] ?? null;
            unset($row['_resolved_user_id']);

            if ($resolved_user_id !== null) {
                $user_id_assignments[$order_id] = $resolved_user_id;
            }

            // Extract the internally-resolved admin user_id (not a real CSV column).
            $resolved_admin_user_id = $row['_resolved_admin_user_id'] ?? null;
            unset($row['_resolved_admin_user_id']);

            if ($resolved_admin_user_id !== null) {
                $admin_user_id_assignments[$order_id] = $resolved_admin_user_id;
            }

            $is_new_record = !isset($existing_set[$order_id]);

            // For new records, derive created_at from request_date so the stored
            // timestamp reflects the actual order date rather than the import time.
            // Existing records always retain their original created_at (excluded from
            // the ON DUPLICATE KEY UPDATE clause below).
            $created_at_for_row = $now;
            if ($is_new_record) {
                $request_date_str = (string) ($row['request_date'] ?? '');
                if ($request_date_str !== '') {
                    try {
                        $parsed = Carbon::createFromFormat('m/d/Y', $request_date_str);
                        if ($parsed instanceof Carbon) {
                            $created_at_for_row = $parsed->startOfDay()->toDateTimeString();
                        }
                    } catch (\Exception) {
                        // Fall back to import time
                    }
                }
            }

            // Always include id and created_at so every row in the chunk has identical
            // column shape. For existing rows, ON DUPLICATE KEY UPDATE excludes both
            // columns, so the generated values are never written to the database.
            $row['id']         = Str::uuid()->toString();
            $row['created_at'] = $created_at_for_row;
            $row['updated_at'] = $now;

            if ($is_new_record) {
                $chunk_created++;
            } else {
                $chunk_updated++;
            }

            $rows[] = $row;
        }

        if (empty($rows)) {
            return [0, 0, 0];
        }

        // Exclude id, order_id, and created_at from the ON DUPLICATE KEY UPDATE clause.
        // All rows now have identical column shapes (id and created_at are always set),
        // so the upsert can be expressed as a single bulk statement without fallback.
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
                        // Derive created_at from request_date for new records.
                        $request_date_str      = (string) ($row['request_date'] ?? '');
                        $created_at_for_insert = $now;
                        if ($request_date_str !== '') {
                            try {
                                $parsed = Carbon::createFromFormat('m/d/Y', $request_date_str);
                                if ($parsed instanceof Carbon) {
                                    $created_at_for_insert = $parsed->startOfDay()->toDateTimeString();
                                }
                            } catch (\Exception) {
                                // Fall back to import time
                            }
                        }

                        DB::table('link_building_order_placements')->insert(
                            array_merge($row, [
                                'id'         => Str::uuid()->toString(),
                                'order_id'   => $order_id,
                                'created_at' => $created_at_for_insert,
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

        $chunk_assigned = count($admin_user_id_assignments);

        // Phase 2: set user_id for rows whose CSV "client" column matched a client's company.
        // Done after the main upsert so existing manual assignments are not cleared for
        // rows where no company match was found.
        foreach ($user_id_assignments as $order_id => $uid) {
            DB::table('link_building_order_placements')
                ->where('order_id', $order_id)
                ->update(['user_id' => $uid, 'updated_at' => $now]);
        }

        // Phase 3: set assigned_admin_user_id for rows whose CSV "Link Builder" column matched
        // an admin-side user by name. Runs after the main upsert so rows without a match
        // retain any existing manually-set assignment.
        foreach ($admin_user_id_assignments as $order_id => $admin_uid) {
            DB::table('link_building_order_placements')
                ->where('order_id', $order_id)
                ->update(['assigned_admin_user_id' => $admin_uid, 'updated_at' => $now]);
        }

        return [$chunk_created, $chunk_updated, $chunk_assigned];
    }

    /**
     * Builds a map of normalized name strings → user_id for all admin-side users
     * (super_admin, admin, staff). Each user is indexed under multiple key variants
     * so that CSV values like "2. Allan, Abigail" or "Tyler Coley" all resolve correctly.
     *
     * Also indexes by email-derived name parts: if the email prefix starts with the
     * user's first name, the remainder is treated as an alternative last name. This
     * allows "Anderson, Kaitlin" to resolve to a user whose legal last name differs
     * from their email alias (e.g. Kaitlin Ogden, email kaitlinanderson@...).
     */
    private function loadAdminUserNameMap(): array
    {
        $map = [];

        User::whereHas('roles', fn ($q) => $q->whereIn('name', ['super_admin', 'admin', 'staff']))
            ->get(['id', 'first_name', 'last_name', 'email'])
            ->each(function (User $user) use (&$map) {
                $first = strtolower(trim((string) $user->first_name));
                $last  = strtolower(trim((string) $user->last_name));

                if ($first !== '' && $last !== '') {
                    $map["{$first} {$last}"] = $user->id; // "abigail allan"
                    $map["{$last} {$first}"] = $user->id; // "allan abigail"
                    $map[$last]              = $user->id; // "allan" (last name only, lower priority)
                } elseif ($last !== '') {
                    $map[$last] = $user->id;
                } elseif ($first !== '') {
                    $map[$first] = $user->id;
                }

                // Email-based fallback: if the email prefix (letters only) starts with
                // the user's first name, treat the remainder as an alternative last name.
                // Example: email "kaitlinanderson@..." → first="kaitlin" → email_last="anderson"
                //          adds "kaitlin anderson", "anderson kaitlin", "anderson" to the map.
                if ($first !== '' && filled($user->email)) {
                    $email_prefix = strtolower(preg_replace('/[^a-z]/i', '', explode('@', $user->email)[0]));
                    if ($email_prefix !== '' && str_starts_with($email_prefix, $first) && strlen($email_prefix) > strlen($first)) {
                        $email_last = substr($email_prefix, strlen($first));
                        if ($email_last !== '' && $email_last !== $last) {
                            $map["{$first} {$email_last}"] = $user->id;
                            $map["{$email_last} {$first}"] = $user->id;
                            $map[$email_last]               = $user->id;
                        }
                    }
                }
            });

        return $map;
    }

    /**
     * Parses a raw "Link Builder" CSV value and returns the matching admin user ID, or null.
     *
     * Supported formats (as exported from Google Sheets):
     *   "2. Allan, Abigail"  → last="Allan", first="Abigail"
     *   "1. Coley, Tyler"    → last="Coley",  first="Tyler"
     *   "Tyler Coley"        → first="Tyler", last="Coley"
     *   "Allan"              → last="Allan" only
     */
    private function resolveAdminUserId(string $raw, array $admin_user_name_map): ?int
    {
        // Strip leading "N. " prefix (e.g. "2. Allan, Abigail" → "Allan, Abigail")
        $value = preg_replace('/^\d+\.\s*/', '', $raw);
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        // Format: "LastName, FirstName" (Google Sheets export style)
        if (str_contains($value, ',')) {
            [$last, $first] = array_map('trim', explode(',', $value, 2));
            $last  = strtolower($last);
            $first = strtolower($first);

            // Try "firstname lastname"
            if ($first !== '' && $last !== '' && isset($admin_user_name_map["{$first} {$last}"])) {
                return $admin_user_name_map["{$first} {$last}"];
            }
            // Try "lastname firstname"
            if ($last !== '' && $first !== '' && isset($admin_user_name_map["{$last} {$first}"])) {
                return $admin_user_name_map["{$last} {$first}"];
            }
            // Try last name alone
            if ($last !== '' && isset($admin_user_name_map[$last])) {
                return $admin_user_name_map[$last];
            }

            return null;
        }

        // No comma — try as "Firstname Lastname" or a single word
        $normalized = strtolower($value);

        if (isset($admin_user_name_map[$normalized])) {
            return $admin_user_name_map[$normalized];
        }

        // Split by space and try last word as last name
        $parts = preg_split('/\s+/', $normalized);
        if (is_array($parts) && count($parts) >= 2) {
            $last_word = end($parts);
            if ($last_word !== false && isset($admin_user_name_map[$last_word])) {
                return $admin_user_name_map[$last_word];
            }
        }

        return null;
    }

    /**
     * Builds a normalized map of company name (lowercase) → user_id for all client
     * accounts that have a company field set. Used during import to auto-assign orders
     * to the matching client account based on the CSV "client" column.
     */
    private function loadClientCompanyMap(): array
    {
        return User::whereHas('roles', fn ($q) => $q->where('name', 'client'))
            ->whereNotNull('company')
            ->where('company', '!=', '')
            ->orderBy('id')
            ->get(['id', 'company'])
            ->mapWithKeys(fn ($u) => [strtolower(trim((string) $u->company)) => $u->id])
            ->all();
    }

    private function passesLinkTypeFilter(string $link_type): bool
    {
        if ($this->link_type_filter === 'all') {
            return true;
        }

        $lower = strtolower($link_type);

        if ($this->link_type_filter === 'external_only') {
            return str_contains($lower, 'external');
        }

        if ($this->link_type_filter === 'internal_only') {
            return str_contains($lower, 'internal');
        }

        return true;
    }

    private function passesDateFilter(string $date_str): bool
    {
        if ($this->date_from === null && $this->date_to === null) {
            return true;
        }

        // Records without a request_date are not excluded by the date range filter.
        // The filter restricts records whose date falls outside the range, not records
        // that simply lack a date value.
        if ($date_str === '') {
            return true;
        }

        $date = $this->parseDateFlexible($date_str);

        if ($date === null) {
            return false;
        }

        if ($this->date_from !== null) {
            try {
                $from = Carbon::createFromFormat('m/d/Y', $this->date_from)->startOfDay();
                if ($date->lt($from)) {
                    return false;
                }
            } catch (\Exception) {
                // Invalid date_from bound — skip this check
            }
        }

        if ($this->date_to !== null) {
            try {
                $to = Carbon::createFromFormat('m/d/Y', $this->date_to)->endOfDay();
                if ($date->gt($to)) {
                    return false;
                }
            } catch (\Exception) {
                // Invalid date_to bound — skip this check
            }
        }

        return true;
    }

    /**
     * Parses a date string from the variety of US-formatted (month/day/year) values
     * that appear in imported CSV files:
     *
     *   04/15/2026          → 4-digit year
     *   4/15/2026           → no leading zero on month/day, 4-digit year
     *   04/15/26            → 2-digit year
     *   4/15/26             → no leading zero on month/day, 2-digit year
     *   04/15/26 12:38 PM   → date extracted, time discarded (Google Sheets datetime export)
     *   05/28/26 7:29 PM    → single-digit hour, date extracted, time discarded
     *   4/15/2026 12:38 PM  → date extracted, time discarded
     *
     * Strategy: extract the month/day/year components directly with a regex and build
     * the date numerically, rather than looping through Carbon::createFromFormat()
     * attempts. That loop-based approach previously had a critical bug: PHP's
     * DateTime::createFromFormat() is lenient about digit count, so a 4-digit-year
     * format like "m/d/Y" silently "succeeds" against a 2-digit year (e.g. "04/15/26"
     * is parsed as year 26 AD instead of failing over to the "m/d/y" format). Since no
     * exception was thrown, the bogus year-26 date was accepted as the parse result —
     * which then always fails the "last year" import date filter and the row is
     * silently skipped. Parsing the year digit count explicitly avoids this ambiguity.
     *
     * All two-digit years are assumed to be in the 2000s (US date convention for this
     * import), since the source data only contains recent/near-future order dates.
     *
     * Returns a Carbon instance (normalized to start-of-day) or null if the value
     * cannot be parsed.
     */
    private function parseDateFlexible(string $raw): ?Carbon
    {
        $value = trim($raw);

        if ($value === '') {
            return null;
        }

        // Normalize whitespace (collapses the gap before any trailing time suffix).
        $value = preg_replace('/\s+/', ' ', $value);

        // Extract the leading m/d/y(y) portion and ignore any trailing time suffix
        // such as " 12:38 PM" or " 7:29 PM" — since we always normalize to
        // start-of-day, the time portion is never needed.
        if (! preg_match('#^(\d{1,2})/(\d{1,2})/(\d{2,4})#', $value, $date_match)) {
            // Last resort: let Carbon attempt a generic parse for anything that
            // doesn't match the expected m/d/y(y) shape.
            try {
                return Carbon::parse($value)->startOfDay();
            } catch (\Exception) {
                return null;
            }
        }

        $month     = (int) $date_match[1];
        $day       = (int) $date_match[2];
        $year_part = $date_match[3];

        $year = match (strlen($year_part)) {
            2       => 2000 + (int) $year_part, // 2-digit year → assume 2000s
            4       => (int) $year_part,
            default => null, // unexpected digit count (e.g. 3 digits) — not a valid year
        };

        if ($year === null || $month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return null;
        }

        try {
            $parsed = Carbon::createSafe($year, $month, $day);

            return $parsed instanceof Carbon ? $parsed->startOfDay() : null;
        } catch (\Exception) {
            return null;
        }
    }

    private function saveProgress(
        string $status,
        int $processed = 0,
        int $created = 0,
        int $updated = 0,
        int $skipped = 0,
        int $assigned = 0,
        array $errors = [],
        array $skipped_records = []
    ): void {
        Cache::put("lbo_import_{$this->import_id}", [
            'status'          => $status,
            'total'           => $this->total_rows,
            'processed'       => $processed,
            'created'         => $created,
            'updated'         => $updated,
            'skipped'         => $skipped,
            'assigned'        => $assigned,
            'errors'          => array_slice($errors, 0, 50),
            'skipped_records' => array_slice($skipped_records, 0, 100),
        ], now()->addHours(2));
    }
}
