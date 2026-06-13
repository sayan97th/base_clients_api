<?php

namespace App\Console\Commands\Dashboard;

use App\Models\LinkBuildingOrderPlacement;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SyncRequestDateToCreatedAt extends Command
{
    protected $signature = 'link-building:sync-request-date-to-created-at
                            {--dry-run : Preview changes without saving anything}
                            {--chunk=200 : Number of records processed per database chunk}';

    protected $description = 'Sets created_at to match request_date for placements with a valid date on or before June 12, 2026. Records with an unparseable date or a date after the cutoff are skipped without modification.';

    /**
     * Only records whose request_date resolves to a date on or before this cutoff are updated.
     * Records with a later date are silently skipped.
     */
    private const CUTOFF_DATE = '2026-06-12';

    /**
     * Date formats attempted in order when parsing a request_date string.
     * The primary application format (MM/DD/YYYY) is tried first; the remaining
     * entries cover common variants that may appear in older imported data.
     */
    private const DATE_FORMATS = [
        'm/d/Y',   // MM/DD/YYYY  — primary app format
        'n/j/Y',   // M/D/YYYY   — no leading zeros
        'Y-m-d',   // YYYY-MM-DD — ISO 8601
        'd/m/Y',   // DD/MM/YYYY
        'm-d-Y',   // MM-DD-YYYY
        'd-m-Y',   // DD-MM-YYYY
        'Y/m/d',   // YYYY/MM/DD
        'n-j-Y',   // M-D-YYYY   — no leading zeros, dashes
    ];

    public function handle(): int
    {
        $is_dry_run = (bool) $this->option('dry-run');
        $chunk_size = max(1, (int) $this->option('chunk'));
        $cutoff     = Carbon::parse(self::CUTOFF_DATE)->endOfDay();

        $query = LinkBuildingOrderPlacement::whereNotNull('request_date')
            ->where('request_date', '!=', '');

        $total = $query->count();

        if ($total === 0) {
            $this->info('No records with a request_date found. Nothing to process.');
            return self::SUCCESS;
        }

        $this->info("Processing {$total} record(s) with a request_date value." . ($is_dry_run ? ' (dry-run — no changes will be saved)' : ''));

        $updated          = 0;
        $skipped_invalid  = 0;
        $skipped_future   = 0;

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunk($chunk_size, function ($placements) use ($cutoff, $is_dry_run, $bar, &$updated, &$skipped_invalid, &$skipped_future) {
            foreach ($placements as $placement) {
                $parsed = $this->parseRequestDate($placement->request_date);

                if ($parsed === null) {
                    $skipped_invalid++;
                    $bar->advance();
                    continue;
                }

                if ($parsed->gt($cutoff)) {
                    $skipped_future++;
                    $bar->advance();
                    continue;
                }

                if (! $is_dry_run) {
                    $placement->timestamps = false;
                    $placement->created_at = $parsed->startOfDay();
                    $placement->save();
                }

                $updated++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->newLine();

        $this->table(
            ['Result', 'Count'],
            [
                ['Updated (created_at set from request_date)', $updated],
                ['Skipped — unparseable date',                 $skipped_invalid],
                ['Skipped — date after ' . self::CUTOFF_DATE,  $skipped_future],
            ]
        );

        if ($is_dry_run) {
            $this->warn('Dry-run completed — no records were modified. Re-run without --dry-run to apply changes.');
        } else {
            $this->info("Done. {$updated} record(s) updated.");
        }

        return self::SUCCESS;
    }

    /**
     * Attempts to parse a raw request_date string into a Carbon instance.
     *
     * Each format in DATE_FORMATS is tried in sequence. A format match is only
     * accepted when Carbon can also re-format the parsed date back to the original
     * string, which prevents ambiguous parses (e.g. 01/02/2025 matching d/m/Y
     * when the real meaning is m/d/Y).
     *
     * As a final safety net, Carbon::parse() is used for any remaining ISO-like
     * strings that do not match the explicit formats above.
     *
     * Returns null when no valid date can be determined.
     */
    private function parseRequestDate(?string $date_str): ?Carbon
    {
        if (empty($date_str)) {
            return null;
        }

        $date_str = trim($date_str);

        foreach (self::DATE_FORMATS as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $date_str);

                // Reject if Carbon silently reset an invalid portion (e.g. day 32 → day 1)
                if ($parsed instanceof Carbon && $parsed->format($format) === $date_str) {
                    return $parsed;
                }
            } catch (\Exception) {
                // Try the next format
            }
        }

        // Last-resort: Carbon::parse() handles ISO variants and human-readable strings
        try {
            $parsed = Carbon::parse($date_str);

            // Reject implausible years that indicate a failed parse (epoch default)
            if ($parsed->year >= 1970 && $parsed->year <= 2100) {
                return $parsed;
            }
        } catch (\Exception) {
            // Unparseable — fall through to null
        }

        return null;
    }
}
