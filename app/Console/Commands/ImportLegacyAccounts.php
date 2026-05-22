<?php

namespace App\Console\Commands;

use App\Models\BillingAddress;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPreference;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class ImportLegacyAccounts extends Command
{
    protected $signature = 'accounts:import
                            {file : Absolute or relative path to the CSV accounts export file}
                            {--dry-run : Preview the import without saving any data}
                            {--force : Skip duplicate accounts instead of failing}
                            {--default-password= : Set a fixed password for all imported accounts (random per user if omitted)}';

    protected $description = 'Import legacy user accounts from a CSV export file into the new application';

    private const FIELD_MAPPING = [
        'name_f'             => 'users.first_name',
        'name_l'             => 'users.last_name',
        'email'              => 'users.email',
        'phone'              => 'users.phone',
        'last_login'         => 'users.last_login_at',
        'created_at'         => 'users.created_at',
        'balance'            => 'users.credit_balance',
        'stripe_customer_id' => 'users.stripe_customer_id',
        'status'             => 'users.is_active  (>=3 → active)',
        'company'            => 'billing_addresses.company',
        'tax_id'             => 'billing_addresses.tax_id',
        'address_street'     => 'billing_addresses.address',
        'address_city'       => 'billing_addresses.city',
        'address_state'      => 'billing_addresses.state_province',
        'address_postcode'   => 'billing_addresses.postal_code',
        'address_country'    => 'billing_addresses.country',
        'timezone'           => 'user_preferences.timezone',
        'i_am_interested_in' => 'user_preferences.interested_in',
    ];

    private const SKIPPED_FIELDS = [
        'id',
        'note',
        'Spent',
        'manager_id',
        'referrer_id',
        'google_studio_link',
    ];

    public function handle(): int
    {
        $file_path       = $this->argument('file');
        $dry_run         = (bool) $this->option('dry-run');
        $force           = (bool) $this->option('force');
        $default_password = $this->option('default-password');

        if (!file_exists($file_path)) {
            $this->error("File not found: {$file_path}");
            return self::FAILURE;
        }

        $this->info('=== Legacy Account Import ===');
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
        $this->line("Found <fg=yellow>{$total}</> account(s) in the file.");
        $this->newLine();

        $this->printFieldMapping($rows[0] ?? []);

        if (!$this->confirm('Proceed with the import?', true)) {
            $this->info('Import cancelled.');
            return self::SUCCESS;
        }

        $this->newLine();

        $organization = Organization::findDefault();
        if (!$organization) {
            $this->error('Default organization not found. Run database seeders first (php artisan db:seed).');
            return self::FAILURE;
        }

        $user_role = Role::firstOrCreate(
            ['name' => 'user'],
            ['display_name' => 'User', 'description' => 'Standard user']
        );

        $stats  = ['imported' => 0, 'skipped' => 0, 'failed' => 0];
        $errors = [];

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        foreach ($rows as $index => $row) {
            $csv_line = $index + 2;
            $email    = trim($row['email'] ?? '');

            try {
                $result = $this->processRow($row, $organization, $user_role, $default_password, $dry_run, $force);
                $stats[$result]++;
            } catch (Throwable $e) {
                $stats['failed']++;
                $errors[] = "Line {$csv_line} [{$email}]: {$e->getMessage()}";
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

        $rows    = [];
        $headers = null;

        while (($columns = fgetcsv($handle)) !== false) {
            if ($headers === null) {
                // Strip BOM, whitespace, and trailing colons from header names
                $headers = array_map(
                    fn (string $h) => rtrim(trim(ltrim($h, "\xEF\xBB\xBF")), ':'),
                    $columns
                );
                continue;
            }

            if (count($columns) !== count($headers)) {
                continue;
            }

            $rows[] = array_combine($headers, $columns);
        }

        fclose($handle);

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
            $target      = self::FIELD_MAPPING[$field] ?? null;
            $is_skipped  = in_array($field, self::SKIPPED_FIELDS, true);
            $status_label = $target
                ? '<fg=green>Mapped</>'
                : ($is_skipped ? '<fg=yellow>Skipped (no target)</>' : '<fg=red>Unknown</>');

            $table_rows[] = [$field, $target ?? '—', $status_label];
        }

        $this->table(['CSV Column', 'Target Field', 'Status'], $table_rows);

        $this->newLine();
        $this->line('<fg=yellow>Note:</> Imported accounts will not have a password. Users must use "Forgot Password" to set their credentials.');
        $this->newLine();
    }

    private function processRow(
        array $row,
        Organization $organization,
        Role $user_role,
        ?string $default_password,
        bool $dry_run,
        bool $force,
    ): string {
        $email = trim($row['email'] ?? '');

        if ($email === '') {
            throw new \RuntimeException('Missing email address');
        }

        if (Validator::make(['email' => $email], ['email' => 'required|email'])->fails()) {
            throw new \RuntimeException("Invalid email address: {$email}");
        }

        if (User::where('email', $email)->exists()) {
            if ($force) {
                return 'skipped';
            }
            throw new \RuntimeException("Account already exists: {$email}");
        }

        if ($dry_run) {
            return 'imported';
        }

        DB::transaction(function () use ($row, $email, $organization, $user_role, $default_password): void {
            $created_at    = $this->parseDate($row['created_at'] ?? null) ?? now();
            $last_login_at = $this->parseDate($row['last_login'] ?? null);
            $is_active     = ((int) ($row['status'] ?? 0)) >= 3;
            $credit_balance = $this->parseDecimal($row['balance'] ?? null);

            $user = new User();
            $user->forceFill([
                'first_name'         => trim($row['name_f'] ?? ''),
                'last_name'          => trim($row['name_l'] ?? ''),
                'email'              => $email,
                'password'           => Hash::make($default_password ?? Str::password(20)),
                'phone'              => $this->nullable($row['phone'] ?? null),
                'stripe_customer_id' => $this->nullable($row['stripe_customer_id'] ?? null),
                'is_active'          => $is_active,
                'credit_balance'     => $credit_balance,
                'last_login_at'      => $last_login_at,
                'email_verified_at'  => $created_at,
                'organization_id'    => $organization->id,
                'created_at'         => $created_at,
                'updated_at'         => $created_at,
            ]);
            $user->save();

            BillingAddress::create([
                'user_id'        => $user->id,
                'company'        => $this->nullable($row['company'] ?? null),
                'tax_id'         => $this->nullable($row['tax_id'] ?? null),
                'address'        => $this->nullable($row['address_street'] ?? null),
                'city'           => $this->nullable($row['address_city'] ?? null),
                'state_province' => $this->nullable($row['address_state'] ?? null),
                'postal_code'    => $this->nullable($row['address_postcode'] ?? null),
                'country'        => $this->nullable($row['address_country'] ?? null),
                'billing_email'  => $email,
            ]);

            UserPreference::create([
                'user_id'       => $user->id,
                'timezone'      => $this->parseTimezone($row['timezone'] ?? null),
                'language'      => 'en',
                'interested_in' => $this->parseInterestedIn($row['i_am_interested_in'] ?? null),
            ]);

            $user->syncRoles([$user_role->name]);
        });

        return 'imported';
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

    private function parseDecimal(?string $value): float
    {
        $cleaned = preg_replace('/[^0-9.]/', '', trim($value ?? ''));
        return (float) ($cleaned ?: '0');
    }

    private function nullable(?string $value): ?string
    {
        $trimmed = trim($value ?? '');
        return $trimmed !== '' ? $trimmed : null;
    }

    private function parseTimezone(?string $value): string
    {
        $timezone = trim($value ?? '');
        return in_array($timezone, \DateTimeZone::listIdentifiers(), true)
            ? $timezone
            : 'UTC';
    }

    private function parseInterestedIn(?string $value): string
    {
        $normalized = strtolower(trim($value ?? ''));

        if ($normalized === '') {
            return 'nothing';
        }

        $has_links   = str_contains($normalized, 'link');
        $has_content = str_contains($normalized, 'content');

        if ($has_links && $has_content) {
            return 'both';
        }

        if ($has_links) {
            return 'links';
        }

        if ($has_content) {
            return 'content';
        }

        return match ($normalized) {
            'both'    => 'both',
            'links'   => 'links',
            'content' => 'content',
            default   => 'nothing',
        };
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
            $this->info("Import complete. Users must use 'Forgot Password' to access their accounts.");
        }
    }
}
