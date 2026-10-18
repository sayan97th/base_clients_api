<?php

namespace App\Listeners;

use App\Services\EmailDeliveryService;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Enforces the Email Notification Settings delivery policy on every outgoing
 * email. MessageSending fires for every mail path in the codebase (queued
 * SendEmailJob, Mail::to()->send()/queue() in controllers, and mail channel
 * Notifications), and Laravel cancels the send when a listener returns false,
 * so this is the single choke point where an email can be stopped.
 *
 * Recipients that are blocked (email type disabled globally, recipient muted
 * every email, or recipient opted out of this type) are removed from the
 * message and logged. The send is cancelled only when nobody is left.
 *
 * Returns null whenever the email is allowed: any other non null value would
 * stop Laravel from calling the remaining MessageSending listeners.
 */
class EnforceEmailDeliveryPolicyListener
{
    private const RECIPIENT_HEADERS = ['To', 'Cc', 'Bcc'];

    public function handle(MessageSending $event): ?bool
    {
        if (EmailDeliveryService::isExempt($event->data)) {
            return null;
        }

        $mail_class = EmailDeliveryService::resolveMailClass($event->data) ?? 'Unknown';
        $email_type = EmailDeliveryService::resolveEmailType($event->data);

        if ($email_type === null) {
            Log::warning('Outgoing email is not registered in EmailTypeCatalog, delivered without policy checks.', [
                'mail_class' => $mail_class,
            ]);

            return null;
        }

        $message           = $event->message;
        $subject           = $message->getSubject();
        $allowed_count     = 0;
        $blocked_by_header = [];

        foreach (self::RECIPIENT_HEADERS as $header) {
            $allowed_addresses = [];

            foreach ($this->getAddresses($message, $header) as $address) {
                $reason = EmailDeliveryService::getBlockReason($email_type, $address->getAddress());

                if ($reason === null) {
                    $allowed_addresses[] = $address;
                    continue;
                }

                $blocked_by_header[$header] = true;

                EmailDeliveryService::logSuppression(
                    mailable_class:  $mail_class,
                    email_type:      $email_type,
                    recipient_email: $address->getAddress(),
                    subject:         $subject,
                    reason:          $reason,
                );
            }

            $allowed_count += count($allowed_addresses);

            if (isset($blocked_by_header[$header])) {
                $this->setAddresses($message, $header, $allowed_addresses);
            }
        }

        return $allowed_count === 0 ? false : null;
    }

    /**
     * @return Address[]
     */
    private function getAddresses(Email $message, string $header): array
    {
        return match ($header) {
            'To'  => $message->getTo(),
            'Cc'  => $message->getCc(),
            'Bcc' => $message->getBcc(),
        };
    }

    /**
     * @param Address[] $addresses
     */
    private function setAddresses(Email $message, string $header, array $addresses): void
    {
        if (empty($addresses)) {
            $message->getHeaders()->remove($header);

            return;
        }

        match ($header) {
            'To'  => $message->to(...$addresses),
            'Cc'  => $message->cc(...$addresses),
            'Bcc' => $message->bcc(...$addresses),
        };
    }
}
