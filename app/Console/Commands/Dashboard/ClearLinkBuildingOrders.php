<?php

namespace App\Console\Commands\Dashboard;

use App\Models\LinkBuildingOrderPlacement;
use Illuminate\Console\Command;

class ClearLinkBuildingOrders extends Command
{
    protected $signature = 'link-building:clear-dashboard
                            {--force : Bypass all interactive prompts and execute immediately (use with extreme caution)}';

    protected $description = 'DESTRUCTIVE — permanently wipes every record from the link building dashboard table.
                              Intended as a one-time clean-slate step before re-importing fresh CSV data.
                              Protected by multiple confirmation gates to prevent accidental execution.';

    private const CONFIRM_PHRASE = 'CONFIRM';

    public function handle(): int
    {
        $this->renderWarningBanner();

        // ── Safety gate 1: environment notice ─────────────────────────────────────

        $env = app()->environment();
        if ($env === 'production') {
            $this->error('  You are running this command in the PRODUCTION environment.  ');
            $this->newLine();
        } else {
            $this->warn("  Current environment: {$env}");
            $this->newLine();
        }

        // ── Safety gate 2: record breakdown ───────────────────────────────────────

        $total            = LinkBuildingOrderPlacement::count();
        $admin_created    = LinkBuildingOrderPlacement::whereNotNull('order_id')->count();
        $client_purchased = LinkBuildingOrderPlacement::whereNull('order_id')->whereNotNull('order_item_id')->count();
        $user_assigned    = LinkBuildingOrderPlacement::whereNull('order_id')->whereNull('order_item_id')->whereNotNull('user_id')->count();

        if ($total === 0) {
            $this->info('The link building dashboard table is already empty. Nothing to delete.');
            return self::SUCCESS;
        }

        $this->line('  Records that will be <fg=red>permanently deleted</>:');
        $this->newLine();
        $this->line("    Total .............. <fg=red;options=bold>{$total}</>");
        $this->line("    Admin-created ...... {$admin_created}");
        $this->line("    Client-purchased ... {$client_purchased}");
        $this->line("    User-assigned ...... {$user_assigned}");
        $this->newLine();
        $this->warn('  ALL of the above records will be removed. This CANNOT be undone.');
        $this->newLine();

        if ($this->option('force')) {
            $this->warn('  --force flag detected. Skipping interactive confirmation gates.');
            $this->newLine();
            return $this->executeDelete($total);
        }

        // ── Safety gate 3: first yes/no confirmation ──────────────────────────────

        if (! $this->confirm("  Do you want to permanently delete all {$total} record(s)?", false)) {
            $this->info('  Operation cancelled. No records were deleted.');
            return self::SUCCESS;
        }

        $this->newLine();

        // ── Safety gate 4: typed phrase confirmation ──────────────────────────────

        $this->line('  To continue, type <options=bold>' . self::CONFIRM_PHRASE . '</> exactly as shown and press Enter.');
        $this->line('  Type anything else to cancel.');
        $this->newLine();

        $typed = $this->ask('  Your input');

        if ($typed !== self::CONFIRM_PHRASE) {
            $this->error("  Input did not match. Expected \"" . self::CONFIRM_PHRASE . "\", got \"{$typed}\".");
            $this->info('  Operation cancelled. No records were deleted.');
            return self::SUCCESS;
        }

        $this->newLine();

        // ── Safety gate 5: countdown ──────────────────────────────────────────────

        $this->warn('  Proceeding in 3 seconds — press Ctrl+C to abort.');

        for ($i = 3; $i >= 1; $i--) {
            $this->line("  {$i}…");
            sleep(1);
        }

        $this->newLine();

        return $this->executeDelete($total);
    }

    // ── Private helpers ───────────────────────────────────────────────────────────

    private function executeDelete(int $expected_count): int
    {
        $this->line('  Deleting all link building dashboard records…');

        $deleted = LinkBuildingOrderPlacement::query()->delete();

        $this->newLine();
        $this->info("  Done. {$deleted} of {$expected_count} record(s) deleted successfully.");

        return self::SUCCESS;
    }

    private function renderWarningBanner(): void
    {
        $this->newLine();
        $this->line('<fg=red>╔══════════════════════════════════════════════════════════════╗</>');
        $this->line('<fg=red>║           DESTRUCTIVE OPERATION — READ CAREFULLY             ║</>');
        $this->line('<fg=red>║                                                              ║</>');
        $this->line('<fg=red>║  This command will WIPE the entire link building dashboard   ║</>');
        $this->line('<fg=red>║  table. Every placement record will be permanently removed.  ║</>');
        $this->line('<fg=red>║                                                              ║</>');
        $this->line('<fg=red>║  Only run this immediately before a clean re-import.         ║</>');
        $this->line('<fg=red>╚══════════════════════════════════════════════════════════════╝</>');
        $this->newLine();
    }
}
