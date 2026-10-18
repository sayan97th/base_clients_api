<?php

namespace Tests\Feature\EmailNotification;

use App\Listeners\EnforceEmailDeliveryPolicyListener;
use App\Mail\AdminInvoicePaidNotification;
use App\Mail\InterceptedEmailCopy;
use App\Mail\TestEmail;
use App\Models\EmailSuppressionLog;
use App\Models\EmailTypeSetting;
use App\Notifications\InvoiceReminderNotification;
use App\Notifications\ResetPasswordNotification;
use App\Services\EmailDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Symfony\Component\Mime\Address;
use Tests\Feature\EmailNotification\Concerns\InteractsWithEmailDelivery;
use Tests\TestCase;

class EnforceEmailDeliveryPolicyListenerTest extends TestCase
{
    use RefreshDatabase, InteractsWithEmailDelivery;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpEmailDelivery();
    }

    private function handle(MessageSending $event): ?bool
    {
        return (new EnforceEmailDeliveryPolicyListener())->handle($event);
    }

    private function addresses(array $addresses): array
    {
        return array_map(fn (Address $address) => $address->getAddress(), $addresses);
    }

    public function test_allowed_email_is_left_untouched(): void
    {
        $admin   = $this->makeUserWithRole('admin');
        $message = $this->makeSymfonyMessage([$admin->email]);

        $result = $this->handle(new MessageSending($message, ['__laravel_mailable' => AdminInvoicePaidNotification::class]));

        $this->assertNull($result);
        $this->assertSame([$admin->email], $this->addresses($message->getTo()));
        $this->assertSame(0, EmailSuppressionLog::count());
    }

    public function test_blocked_cc_and_bcc_recipients_are_removed_while_to_is_kept(): void
    {
        $allowed     = $this->makeUserWithRole('admin');
        $muted_cc    = $this->makeUserWithRole('admin');
        $muted_bcc   = $this->makeUserWithRole('staff');
        $allowed_bcc = $this->makeUserWithRole('staff');
        $this->setEmailPreference($muted_cc, true);
        $this->setEmailPreference($muted_bcc, true);

        $message = $this->makeSymfonyMessage([$allowed->email], [$muted_cc->email], [$muted_bcc->email, $allowed_bcc->email]);

        $result = $this->handle(new MessageSending($message, ['__laravel_mailable' => AdminInvoicePaidNotification::class]));

        $this->assertNull($result);
        $this->assertSame([$allowed->email], $this->addresses($message->getTo()));
        $this->assertSame([], $message->getCc());
        $this->assertSame([$allowed_bcc->email], $this->addresses($message->getBcc()));
        $this->assertSame(2, EmailSuppressionLog::count());
    }

    public function test_send_continues_when_every_to_is_blocked_but_a_cc_is_allowed(): void
    {
        $muted   = $this->makeUserWithRole('admin');
        $allowed = $this->makeUserWithRole('admin');
        $this->setEmailPreference($muted, true);

        $message = $this->makeSymfonyMessage([$muted->email], [$allowed->email]);

        $result = $this->handle(new MessageSending($message, ['__laravel_mailable' => AdminInvoicePaidNotification::class]));

        $this->assertNull($result);
        $this->assertSame([], $message->getTo());
        $this->assertSame([$allowed->email], $this->addresses($message->getCc()));
    }

    public function test_suppression_log_records_every_detail_of_the_blocked_email(): void
    {
        $admin = $this->makeUserWithRole('admin');
        $this->setEmailPreference($admin, false, ['admin_invoice_paid']);

        $message = $this->makeSymfonyMessage([$admin->email]);
        $message->subject('Payment Received, Invoice INV-1');

        $this->handle(new MessageSending($message, ['__laravel_mailable' => AdminInvoicePaidNotification::class]));

        $this->assertDatabaseHas('email_suppression_logs', [
            'mailable_class'  => AdminInvoicePaidNotification::class,
            'email_type'      => 'admin_invoice_paid',
            'recipient_email' => $admin->email,
            'subject'         => 'Payment Received, Invoice INV-1',
            'reason'          => EmailDeliveryService::REASON_RECIPIENT_OPTED_OUT,
        ]);
    }

    public function test_exempt_classes_are_never_blocked_even_for_muted_recipients(): void
    {
        $admin = $this->makeUserWithRole('admin');
        $this->setEmailPreference($admin, true);

        foreach ([TestEmail::class, InterceptedEmailCopy::class] as $exempt_class) {
            $message = $this->makeSymfonyMessage([$admin->email]);

            $this->assertNull($this->handle(new MessageSending($message, ['__laravel_mailable' => $exempt_class])));
            $this->assertSame([$admin->email], $this->addresses($message->getTo()));
        }

        $this->assertSame(0, EmailSuppressionLog::count());
    }

    public function test_mail_notifications_are_resolved_through_the_notification_class_key(): void
    {
        $admin = $this->makeUserWithRole('admin');
        $this->setEmailPreference($admin, true);

        $reset_password = new MessageSending(
            $this->makeSymfonyMessage([$admin->email]),
            ['__laravel_notification' => ResetPasswordNotification::class]
        );
        $this->assertNull($this->handle($reset_password));

        // A client side notification is always delivered, whoever receives it.
        $client = $this->makeUserWithRole('client');
        EmailTypeSetting::create(['email_type' => 'invoice_reminder', 'is_enabled' => false]);

        $reminder = new MessageSending(
            $this->makeSymfonyMessage([$client->email]),
            ['__laravel_notification' => InvoiceReminderNotification::class]
        );
        $this->assertNull($this->handle($reminder));
    }

    public function test_message_data_without_a_mail_class_is_let_through(): void
    {
        $this->assertNull($this->handle(new MessageSending($this->makeSymfonyMessage(['someone@example.com']), [])));
    }
}
