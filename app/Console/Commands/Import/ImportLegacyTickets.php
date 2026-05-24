<?php

namespace App\Console\Commands\Import;

use App\Models\Organization;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class ImportLegacyTickets extends Command
{
    protected $signature = 'tickets:import
                            {file? : Path to the CSV tickets export file (defaults to import/tickets/base-tickets.csv)}
                            {--dry-run : Preview the import without saving any data}
                            {--force : Skip duplicate tickets instead of failing}
                            {--default-priority= : Default priority for all tickets (low, medium, high — defaults to medium)}';

    protected $description = 'Import legacy support tickets from a CSV export file into the new application';

    private const FIELD_MAPPING = [
        'Id'              => 'support_ticket_messages.content (preserved as legacy reference in import note)',
        'Subject'         => 'support_tickets.subject',
        'Client'          => 'support_tickets.user_id (resolved via organization name)',
        'Status'          => 'support_tickets.status',
        'No. of Messages' => 'support_ticket_messages.content (original count stored in import note)',
        'Created'         => 'support_tickets.created_at',
        'Completed'       => 'support_tickets.resolved_at / closed_at (based on resolved/closed status)',
    ];

    private const SKIPPED_FIELDS = [
        'Tags',
    ];

    private const STATUS_MAP = [
        'open'        => 'open',
        'in progress' => 'in_progress',
        'in_progress' => 'in_progress',
        'resolved'    => 'resolved',
        'closed'      => 'closed',
        'completed'   => 'closed',
    ];

    public function handle(): int
    {
        $file_path        = $this->argument('file') ?? base_path('import/tickets/base-tickets.csv');
        $dry_run          = (bool) $this->option('dry-run');
        $force            = (bool) $this->option('force');
        $default_priority = $this->option('default-priority') ?? 'medium';

        if (!in_array($default_priority, SupportTicket::PRIORITIES, true)) {
            $this->error("Invalid priority: {$default_priority}. Allowed values: " . implode(', ', SupportTicket::PRIORITIES));
            return self::FAILURE;
        }

        if (!$this->argument('file')) {
            $this->line("No file specified. Using default: <fg=yellow>import/tickets/base-tickets.csv</>");
            $this->newLine();
        }

        if (!file_exists($file_path)) {
            $this->error("File not found: {$file_path}");
            return self::FAILURE;
        }

        $this->info('=== Legacy Support Ticket Import ===');
        $this->newLine();

        if ($dry_run) {
            $this->warn('[DRY RUN] No data will be saved to the database.');
            $this->newLine();
        }

        $rows = $this->parseCsv($file_path);
        if ($rows === null) {
            return self::FAILURE;
        }

        $total = count($rows);
        $this->line("Found <fg=yellow>{$total}</> ticket(s) in the file.");
        $this->newLine();

        $this->printFieldMapping($rows[0] ?? []);

        if (!$this->confirm('Proceed with the import?', true)) {
            $this->info('Import cancelled.');
            return self::SUCCESS;
        }

        $this->newLine();

        $stats  = ['imported' => 0, 'skipped' => 0, 'failed' => 0];
        $errors = [];

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        foreach ($rows as $index => $row) {
            $csv_line  = $index + 2;
            $legacy_id = trim($row['Id'] ?? '');

            try {
                $result = $this->processRow($row, $default_priority, $dry_run, $force);
                $stats[$result]++;
            } catch (Throwable $e) {
                $stats['failed']++;
                $errors[] = "Line {$csv_line} [ID: {$legacy_id}]: {$e->getMessage()}";
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->printSummary($stats, $errors, $dry_run);

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function parseCsv(string $file_path): ?array
    {
        $handle = fopen($file_path, 'r');
        if ($handle === false) {
            $this->error("Cannot open file: {$file_path}");
            return null;
        }

        $rows        = [];
        $headers     = null;
        $line_number = 0;
        $adjusted    = 0;

        while (($columns = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $line_number++;

            if ($headers === null) {
                $headers = array_map(
                    fn (string $h) => rtrim(trim(ltrim($h, "\xEF\xBB\xBF")), ':'),
                    $columns
                );
                continue;
            }

            $col_count = \count($columns);
            $hdr_count = \count($headers);

            if ($col_count !== $hdr_count) {
                $adjusted++;
                $columns = $col_count < $hdr_count
                    ? array_pad($columns, $hdr_count, '')
                    : \array_slice($columns, 0, $hdr_count);
            }

            $rows[] = array_combine($headers, $columns);
        }

        fclose($handle);

        if ($adjusted > 0) {
            $this->warn("{$adjusted} row(s) had mismatched column counts and were automatically adjusted.");
            $this->newLine();
        }

        if (empty($rows)) {
            $this->error('The CSV file contains no data rows.');
            return null;
        }

        return $rows;
    }

    private function printFieldMapping(array $sample_row): void
    {
        $this->line('<fg=cyan>Field Mapping:</>');
        $this->newLine();

        $table_rows = [];
        foreach ($sample_row as $field => $value) {
            $target       = self::FIELD_MAPPING[$field] ?? null;
            $is_skipped   = in_array($field, self::SKIPPED_FIELDS, true);
            $status_label = $target
                ? '<fg=green>Mapped</>'
                : ($is_skipped ? '<fg=yellow>Skipped (no target)</>' : '<fg=red>Unknown</>');

            $table_rows[] = [$field, $target ?? '—', $status_label];
        }

        $this->table(['CSV Column', 'Target Field', 'Status'], $table_rows);

        $this->newLine();
        $this->line('<fg=yellow>Note:</> Tickets are matched to users via their organization name. Legacy ticket ID and original message count are preserved in an import note attached to each ticket.');
        $this->newLine();
    }

    private function processRow(
        array $row,
        string $default_priority,
        bool $dry_run,
        bool $force,
    ): string {
        $legacy_id   = trim($row['Id'] ?? '');
        $subject     = trim($row['Subject'] ?? '');
        $client_name = trim($row['Client'] ?? '');

        if ($subject === '') {
            throw new \RuntimeException('Missing subject');
        }

        if ($client_name === '') {
            throw new \RuntimeException('Missing client name');
        }

        $user = $this->resolveUser($client_name);
        if (!$user) {
            throw new \RuntimeException("No user found for client: \"{$client_name}\"");
        }

        $status     = $this->mapStatus($row['Status'] ?? '');
        $created_at = $this->parseDate($row['Created'] ?? null) ?? now();
        $completed  = $this->parseDate($row['Completed'] ?? null);
        $msg_count  = (int) ($row['No. of Messages'] ?? 0);

        if (SupportTicket::where('subject', $subject)
            ->where('user_id', $user->id)
            ->whereDate('created_at', $created_at->toDateString())
            ->exists()
        ) {
            if ($force) {
                return 'skipped';
            }
            throw new \RuntimeException("Ticket already exists: \"{$subject}\" for user #{$user->id}");
        }

        if ($dry_run) {
            return 'imported';
        }

        DB::transaction(function () use (
            $legacy_id, $subject, $user, $status, $default_priority,
            $created_at, $completed, $msg_count
        ): void {
            $resolved_at = null;
            $closed_at   = null;

            if ($completed) {
                if ($status === 'resolved') {
                    $resolved_at = $completed;
                } elseif ($status === 'closed') {
                    $closed_at = $completed;
                }
            }

            $ticket = new SupportTicket();
            $ticket->forceFill([
                'subject'     => $subject,
                'status'      => $status,
                'priority'    => $default_priority,
                'user_id'     => $user->id,
                'resolved_at' => $resolved_at,
                'closed_at'   => $closed_at,
                'created_at'  => $created_at,
                'updated_at'  => $created_at,
            ]);
            $ticket->save();

            $note_lines = ['[Imported from legacy system]'];

            if ($legacy_id !== '') {
                $note_lines[] = "Legacy ticket ID: {$legacy_id}";
            }

            if ($msg_count > 0) {
                $note_lines[] = "Original message count: {$msg_count}";
            }

            SupportTicketMessage::create([
                'ticket_id'  => $ticket->id,
                'sender_id'  => $user->id,
                'content'    => implode("\n", $note_lines),
                'created_at' => $created_at,
                'updated_at' => $created_at,
            ]);
        });

        return 'imported';
    }

    private function resolveUser(string $client_name): ?User
    {
        $organization = Organization::where('name', $client_name)->first()
            ?? Organization::where('name', 'like', "%{$client_name}%")->first();

        if ($organization) {
            $user = User::where('organization_id', $organization->id)
                ->orderBy('created_at')
                ->first();

            if ($user) {
                return $user;
            }
        }

        return $this->resolveUserByName($client_name);
    }

    private function resolveUserByName(string $client_name): ?User
    {
        // Strip parenthetical suffixes like "(via Google Docs)"
        $cleaned = trim(preg_replace('/\s*\(.*?\)\s*/', ' ', $client_name));

        if ($cleaned === '') {
            return null;
        }

        $words = preg_split('/\s+/', $cleaned);

        if (\count($words) >= 2) {
            $first = strtolower($words[0]);
            $last  = strtolower(end($words));

            // Exact first + last name match (case-insensitive), oldest user wins on tie
            $user = User::whereRaw('LOWER(first_name) = ?', [$first])
                ->whereRaw('LOWER(last_name) = ?', [$last])
                ->orderBy('created_at')
                ->first();

            if ($user) {
                return $user;
            }

            // Reversed: maybe the name is stored as "Last First" in the CSV
            $user = User::whereRaw('LOWER(first_name) = ?', [$last])
                ->whereRaw('LOWER(last_name) = ?', [$first])
                ->orderBy('created_at')
                ->first();

            if ($user) {
                return $user;
            }
        }

        // Full concatenated name LIKE match as last resort
        $normalized = strtolower($cleaned);

        return User::whereRaw("LOWER(CONCAT(first_name, ' ', last_name)) = ?", [$normalized])
            ->orderBy('created_at')
            ->first();
    }

    private function mapStatus(string $raw_status): string
    {
        $normalized = strtolower(trim($raw_status));
        return self::STATUS_MAP[$normalized] ?? 'open';
    }

    private function parseDate(?string $value): ?Carbon
    {
        $trimmed = trim($value ?? '');
        if ($trimmed === '') {
            return null;
        }

        try {
            return Carbon::parse($trimmed);
        } catch (Throwable) {
            return null;
        }
    }

    private function printSummary(array $stats, array $errors, bool $dry_run): void
    {
        $header = $dry_run ? '=== [DRY RUN] Import Preview ===' : '=== Import Results ===';
        $this->line("<fg=cyan>{$header}</>");
        $this->newLine();

        $this->table(
            ['Result', 'Count'],
            [
                ['Imported', $stats['imported']],
                ['Skipped',  $stats['skipped']],
                ['Failed',   $stats['failed']],
                ['Total',    array_sum($stats)],
            ]
        );

        if (!empty($errors)) {
            $this->newLine();
            $this->error('Errors encountered:');
            foreach ($errors as $error) {
                $this->line("  - {$error}");
            }
        }

        if ($dry_run) {
            $this->newLine();
            $this->warn('No data was saved. Remove --dry-run to execute the import.');
        } elseif ($stats['imported'] > 0) {
            $this->newLine();
            $this->info('Import complete. Legacy ticket metadata has been preserved in the import note of each ticket.');
        }
    }
}
