<?php

namespace App\Listeners;

use App\Services\EmailInterceptService;
use Illuminate\Mail\Events\MessageSending;
use Symfony\Component\Mime\Address;

/**
 * Fires immediately before every outgoing email leaves the application,
 * regardless of whether it was queued through SendEmailJob or sent directly
 * with Mail::to()->send()/queue() from a controller. This is the only choke
 * point common to every mail path in the codebase, so it is where the
 * "Email Interceptor" setting is enforced: a BCC copy is appended to the
 * message for whichever configured recipients are enabled for that
 * audience (admin-side vs client-side), and the copy is logged for the
 * "Recent intercepted copies" list in the admin UI.
 */
class InterceptOutgoingEmailListener
{
    public function handle(MessageSending $event): void
    {
        $message   = $event->message;
        $to_address = $message->getTo()[0] ?? null;

        if (! $to_address) {
            return;
        }

        $to_email = $to_address->getAddress();

        $bcc_recipients = EmailInterceptService::getBccRecipients($to_email);

        if (empty($bcc_recipients)) {
            return;
        }

        $already_addressed = array_map(
            fn (Address $address) => strtolower($address->getAddress()),
            [...$message->getTo(), ...$message->getCc(), ...$message->getBcc()],
        );

        $new_bcc_addresses = [];
        foreach ($bcc_recipients as $recipient_email) {
            if (in_array(strtolower($recipient_email), $already_addressed, true)) {
                continue;
            }
            $new_bcc_addresses[]  = new Address($recipient_email);
            $already_addressed[]  = strtolower($recipient_email);
        }

        if (empty($new_bcc_addresses)) {
            return;
        }

        $message->addBcc(...$new_bcc_addresses);

        EmailInterceptService::logIntercept(
            mailable_class:    $event->data['__laravel_mailable'] ?? 'Unknown',
            to_email:          $to_email,
            subject:           $message->getSubject(),
            copied_to_emails:  array_map(fn (Address $a) => $a->getAddress(), $new_bcc_addresses),
        );
    }
}
