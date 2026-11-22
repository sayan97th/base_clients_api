<?php

namespace App\Console\Commands\SeoSubscription;

use App\Services\SeoSubscriptionBillingService;
use Illuminate\Console\Command;

/**
 * Daily SEO subscription billing run:
 *   1. Expires subscriptions past their end date (they stop renewing).
 *   2. Sends the renewal reminder emails that are due.
 *   3. Charges the renewals that are due, and retries declined ones whose
 *      retry date has arrived.
 *
 * Safe to run any number of times a day: every period is billed at most once
 * and every reminder is sent at most once per period.
 */
class ProcessSeoSubscriptionBilling extends Command
{
    protected $signature = 'seo-subscriptions:process-billing
                            {--reminders-only : Only send renewal reminders, do not charge any card}';

    protected $description = 'Charge due SEO subscription renewals, retry declined payments and send renewal reminders';

    public function handle(SeoSubscriptionBillingService $billing_service): int
    {
        $expired = $billing_service->expireEndedSubscriptions();
        $this->info("Expired {$expired} subscription(s) past their end date.");

        $reminders = $billing_service->sendDueReminders();
        $this->info("Queued {$reminders} renewal reminder email(s).");

        if ($this->option('reminders-only')) {
            return self::SUCCESS;
        }

        $summary = $billing_service->processDueRenewals();

        $this->info(sprintf(
            'Renewals: %d attempted, %d succeeded, %d failed, %d skipped.',
            $summary['attempted'],
            $summary['succeeded'],
            $summary['failed'],
            $summary['skipped'],
        ));

        return self::SUCCESS;
    }
}
