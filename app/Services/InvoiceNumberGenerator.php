<?php

namespace App\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Issues sequential "BSM-XXXX" invoice numbers from a single locked counter
 * row instead of `Invoice::count() + 1`, which raced under concurrent
 * requests and drifted below the highest number already issued whenever an
 * invoice was deleted, both of which produced duplicate invoice numbers.
 *
 * next() must be called from inside the same DB::transaction() that creates
 * the invoice. lockForUpdate() holds the row lock until that transaction
 * commits or rolls back, so a second concurrent caller blocks until the
 * first has either reserved and persisted its number or given the number
 * back up, instead of both reading the same next_number.
 */
class InvoiceNumberGenerator
{
    private const PREFIX       = 'BSM-';
    private const PAD_LENGTH   = 4;
    private const MAX_ATTEMPTS = 3;

    public function next(): string
    {
        $counter = DB::table('invoice_number_counters')->lockForUpdate()->first();

        $reserved_number = $counter->next_number;

        DB::table('invoice_number_counters')
            ->where('id', $counter->id)
            ->update(['next_number' => $reserved_number + 1]);

        return self::PREFIX . str_pad((string) $reserved_number, self::PAD_LENGTH, '0', STR_PAD_LEFT);
    }

    /**
     * Runs $callback inside a fresh DB transaction, retrying on the rare
     * chance an invoice_number still collides, for example a row written by
     * a source outside this generator (the legacy portal). Each retry
     * re-syncs the counter past the highest invoice_number currently on
     * file before trying again, so a stuck counter self-heals instead of
     * failing every request until someone manually fixes the data.
     */
    public function transact(callable $callback): mixed
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                return DB::transaction($callback);
            } catch (QueryException $e) {
                if (! $this->isDuplicateInvoiceNumber($e) || $attempt === self::MAX_ATTEMPTS) {
                    throw $e;
                }

                $this->resyncPastMaxIssuedNumber();
            }
        }
    }

    /**
     * MySQL phrases a duplicate key error as "...for key
     * 'invoices.invoices_invoice_number_unique'", SQLite (used in tests) as
     * "UNIQUE constraint failed: invoices.invoice_number". Matching on
     * "invoice_number" covers both while still excluding unrelated
     * constraints on this table, e.g. a duplicate unique_id.
     */
    private function isDuplicateInvoiceNumber(QueryException $e): bool
    {
        return $e->getCode() === '23000'
            && str_contains($e->getMessage(), 'invoice_number');
    }

    private function resyncPastMaxIssuedNumber(): void
    {
        $max_issued_number = (int) DB::table('invoices')
            ->selectRaw("MAX(CAST(SUBSTRING(invoice_number, 5) AS UNSIGNED)) as max_number")
            ->value('max_number');

        DB::table('invoice_number_counters')->update(['next_number' => $max_issued_number + 1]);
    }
}
