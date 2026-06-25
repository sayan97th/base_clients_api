<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Register MySQL-compatible UDFs for SQLite so production queries work in tests.
        if (DB::getDriverName() === 'sqlite') {
            $pdo = DB::connection()->getPdo();

            // REGEXP: used in WHERE clauses (e.g. order_id REGEXP '^BL-[0-9]+$')
            $pdo->sqliteCreateFunction(
                'REGEXP',
                fn ($pattern, $value) => (bool) preg_match('/' . $pattern . '/', (string) $value),
                2
            );

            // STR_TO_DATE: converts MM/DD/YYYY strings to YYYY-MM-DD for date sorting.
            $pdo->sqliteCreateFunction(
                'STR_TO_DATE',
                function ($value, $format) {
                    if ($value === null || $value === '') {
                        return null;
                    }
                    if ($format === '%m/%d/%Y') {
                        $parts = explode('/', (string) $value);
                        if (count($parts) === 3) {
                            return sprintf('%04d-%02d-%02d', (int) $parts[2], (int) $parts[0], (int) $parts[1]);
                        }
                    }
                    return null;
                },
                2
            );

            // REGEXP_SUBSTR: extracts the first substring matching a pattern.
            // Used to pull the trailing numeric part from BL-{n} order IDs for natural sort.
            $pdo->sqliteCreateFunction(
                'REGEXP_SUBSTR',
                function ($subject, $pattern) {
                    if ($subject === null) {
                        return null;
                    }
                    if (preg_match('/' . $pattern . '/', (string) $subject, $matches)) {
                        return $matches[0];
                    }
                    return null;
                },
                2
            );
        }
    }
}
