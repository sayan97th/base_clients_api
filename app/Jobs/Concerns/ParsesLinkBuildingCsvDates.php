<?php

namespace App\Jobs\Concerns;

use Carbon\Carbon;

/**
 * Shared date parsing/filtering used by both the full order CSV import job and the
 * metrics-only column update job. Consuming classes must declare `$date_from` and
 * `$date_to` (nullable strings in MM/DD/YYYY format).
 */
trait ParsesLinkBuildingCsvDates
{
    private function normalizeHeader(string $header): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9\s]/', ' ', $header);
        $clean = preg_replace('/\s+/', ' ', $clean);

        return strtolower(trim($clean));
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

        $value = preg_replace('/\s+/', ' ', $value);

        if (! preg_match('#^(\d{1,2})/(\d{1,2})/(\d{2,4})#', $value, $date_match)) {
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
            2       => 2000 + (int) $year_part,
            4       => (int) $year_part,
            default => null,
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
}
