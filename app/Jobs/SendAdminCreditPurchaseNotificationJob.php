<?php

namespace App\Jobs;

use App\Mail\AdminCreditPurchaseNotification;
use App\Models\CreditPurchase;
use App\Services\EmailNotificationSettingService;
use App\Support\FrontendUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendAdminCreditPurchaseNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $max_exceptions = 2;

    public array $backoff = [30, 60, 120];

    public function __construct(
        public int $credit_purchase_id,
    ) {
        $this->onQueue('emails');
    }

    public function handle(): void
    {
        $purchase = CreditPurchase::with('user')->find($this->credit_purchase_id);

        if (! $purchase) {
            return;
        }

        $recipients   = EmailNotificationSettingService::resolveAdminRecipients();
        $client       = $purchase->user;
        $client_name  = $client ? trim($client->first_name . ' ' . $client->last_name) : ($client?->email ?? 'Unknown client');
        $client_email = $client?->email ?? '';
        $initials     = $this->buildInitials($client_name);
        $amount_paid  = '$' . number_format((float) $purchase->amount_paid, 2);

        $view_purchases_url = FrontendUrl::to('/admin/credits/purchases');
        $settings_url       = FrontendUrl::to('/admin/email-notifications');
        $purchase_date      = $purchase->created_at->format('F j, Y \a\t g:i A');

        foreach ($recipients as $position => $recipient) {
            SendEmailJob::dispatchWithThrottle(
                new AdminCreditPurchaseNotification(
                    recipient_name:     $recipient['name'],
                    recipient_email:    $recipient['email'],
                    client_name:        $client_name,
                    client_email:       $client_email,
                    client_initials:    $initials,
                    package_name:       $purchase->package_name,
                    credits_amount:     (int) $purchase->credits_amount,
                    amount_paid:        $amount_paid,
                    purchase_date:      $purchase_date,
                    view_purchases_url: $view_purchases_url,
                    settings_url:       $settings_url,
                ),
                $recipient['email'],
                $position,
            );
        }
    }

    public function failed(\Throwable $exception): void
    {
        \Log::error('SendAdminCreditPurchaseNotificationJob failed', [
            'credit_purchase_id' => $this->credit_purchase_id,
            'error'              => $exception->getMessage(),
        ]);
    }

    private function buildInitials(string $name): string
    {
        $parts = array_filter(explode(' ', trim($name)));

        if (count($parts) >= 2) {
            return strtoupper(mb_substr($parts[0], 0, 1) . mb_substr(end($parts), 0, 1));
        }

        return strtoupper(mb_substr($name, 0, 2));
    }
}
