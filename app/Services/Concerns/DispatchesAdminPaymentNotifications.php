<?php

namespace App\Services\Concerns;

use App\Events\PaymentCompleted;
use App\Models\Invoice;
use App\Models\User;
use App\Services\EmailNotificationSettingService;

/**
 * Fires the admin-facing PaymentCompleted event only for the recipients
 * configured in Email Notification Settings, instead of every
 * super_admin/admin/staff account. Every payment path (checkout, deferred
 * payment, public share-link payment, manually created invoices) must use
 * this helper so an admin who opts out of payment notifications stops
 * receiving the "Payment Receipt" email and portal alert everywhere, not
 * just on some of the payment flows.
 */
trait DispatchesAdminPaymentNotifications
{
    private function dispatchAdminPaymentCompletedEvent(
        Invoice $invoice,
        string $payer_name,
        float $amount,
        ?string $link = null,
    ): void {
        $recipients  = EmailNotificationSettingService::resolveAdminRecipients();
        $admin_users = User::whereIn('email', array_column($recipients, 'email'))
            ->where('is_active', true)
            ->get();

        foreach ($admin_users as $admin) {
            event(new PaymentCompleted(
                user:           $admin,
                payer_name:     $payer_name,
                amount:         $amount,
                invoice_number: $invoice->invoice_number,
                link:           $link ?? '/admin/invoices/' . $invoice->id,
                invoice:        $invoice,
            ));
        }
    }
}
