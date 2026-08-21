<?php

namespace App\Services\Concerns;

use App\Events\PaymentCompleted;
use App\Models\Invoice;
use App\Models\User;
use App\Services\EmailNotificationSettingService;

/**
 * Fires the PaymentCompleted event for the invoice's owning client and for the
 * admin recipients configured in Email Notification Settings, instead of every
 * super_admin/admin/staff account. Every payment path (checkout, deferred
 * payment, public share-link payment, manually created invoices) must use
 * this helper so:
 *   - the paying client always receives the "Payment Receipt" notification
 *     (portal + email, subject to their own NotificationPreference), and
 *   - an admin who opts out of payment notifications stops receiving the
 *     "Payment Receipt" email and portal alert everywhere, not just on some
 *     of the payment flows.
 */
trait DispatchesAdminPaymentNotifications
{
    private function dispatchAdminPaymentCompletedEvent(
        Invoice $invoice,
        string $payer_name,
        float $amount,
        ?string $link = null,
    ): void {
        $this->dispatchClientPaymentCompletedEvent($invoice, $payer_name, $amount);

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

    /**
     * Fires the client-facing PaymentCompleted event for the invoice's owning
     * user. Without this, the "Payment Receipt" notification was only ever
     * created for admin recipients, so the paying client never received a
     * portal notification or receipt email for their own purchase.
     */
    private function dispatchClientPaymentCompletedEvent(
        Invoice $invoice,
        string $payer_name,
        float $amount,
    ): void {
        $client = $invoice->user;

        if (! $client || ! $client->is_active) {
            return;
        }

        event(new PaymentCompleted(
            user:           $client,
            payer_name:     $payer_name,
            amount:         $amount,
            invoice_number: $invoice->invoice_number,
            link:           '/invoices/' . $invoice->unique_id,
            invoice:        $invoice,
        ));
    }
}
