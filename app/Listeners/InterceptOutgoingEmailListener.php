<?php

namespace App\Listeners;

use App\Jobs\SendEmailInterceptCopyJob;
use App\Mail\InterceptedEmailCopy;
use App\Services\EmailInterceptService;
use Illuminate\Mail\Events\MessageSending;
use Symfony\Component\Mime\Email;

/**
 * Fires immediately before every outgoing email leaves the application,
 * regardless of whether it was queued through SendEmailJob or sent directly
 * with Mail::to()->send()/queue() from a controller. This is the only choke
 * point common to every mail path in the codebase, so it is where the
 * "Email Interceptor" setting is enforced: for whichever configured
 * recipients are enabled for the message's audience (admin-side vs
 * client-side), an independent SendEmailInterceptCopyJob is queued to resend
 * an exact copy of the rendered email, staggered so consecutive copies never
 * leave less than SendEmailInterceptCopyJob::MIN_STAGGER_SECONDS apart. The
 * copy is also logged for the "Recent intercepted copies" list in the admin UI.
 */
class InterceptOutgoingEmailListener
{
    public function handle(MessageSending $event): void
    {
        $mailable_class = $event->data['__laravel_mailable'] ?? null;

        // Never intercept the interceptor's own copy emails — this is the guard
        // against an infinite loop of copies queuing more copies.
        if ($mailable_class === InterceptedEmailCopy::class) {
            return;
        }

        $message    = $event->message;
        $to_address = $message->getTo()[0] ?? null;

        if (! $to_address) {
            return;
        }

        $to_email = $to_address->getAddress();

        $copy_recipients = EmailInterceptService::getInterceptRecipients($to_email);

        if (empty($copy_recipients)) {
            return;
        }

        $subject   = $message->getSubject() ?? '';
        $html_body = $this->extractHtmlBody($message);

        // One claim per unique (recipient, subject, body): guarantees this exact
        // email is only ever copied once, even if MessageSending somehow fires
        // more than once for it.
        if (! EmailInterceptService::claimIntercept($to_email, $subject, $html_body)) {
            return;
        }

        foreach (array_values($copy_recipients) as $position => $copy_recipient_email) {
            SendEmailInterceptCopyJob::dispatchStaggered(
                original_subject:         $subject,
                original_recipient_email: $to_email,
                html_body:                $html_body,
                copy_recipient_email:     $copy_recipient_email,
                position:                 $position,
            );
        }

        EmailInterceptService::logIntercept(
            mailable_class:    $mailable_class ?? 'Unknown',
            to_email:          $to_email,
            subject:           $subject,
            copied_to_emails:  array_values($copy_recipients),
        );
    }

    private function extractHtmlBody(Email $message): string
    {
        $html = $message->getHtmlBody();

        if (is_resource($html)) {
            return stream_get_contents($html) ?: '';
        }

        if (is_string($html) && $html !== '') {
            return $html;
        }

        $text = $message->getTextBody();

        if (is_resource($text)) {
            return stream_get_contents($text) ?: '';
        }

        return (string) ($text ?? '');
    }
}
