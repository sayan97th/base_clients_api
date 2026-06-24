<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // SQLite does not support REGEXP natively. Register a compatible function
        // so production queries that use REGEXP work correctly under in-memory SQLite.
        if (DB::getDriverName() === 'sqlite') {
            DB::connection()->getPdo()->sqliteCreateFunction(
                'REGEXP',
                fn ($pattern, $value) => (bool) preg_match('/' . $pattern . '/', (string) $value),
                2
            );
        }
    }
}
