<?php

namespace Tests\Feature\EmailNotification\Concerns;

use App\Mail\AdminOrderCommentNotification;
use App\Mail\ClientCommentReplyNotification;
use App\Models\Role;
use App\Models\User;
use App\Models\UserEmailPreference;
use App\Services\EmailDeliveryService;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Mime\Email;

/**
 * Shared fixtures for the Email Notification Settings delivery policy tests:
 * role seeding, admin/client users, personal preferences, sample mailables
 * and access to the messages captured by the "array" mail transport.
 */
trait InteractsWithEmailDelivery
{
    protected function setUpEmailDelivery(): void
    {
        Role::firstOrCreate(['name' => 'admin'],       ['display_name' => 'Admin',       'description' => 'Admin']);
        Role::firstOrCreate(['name' => 'super_admin'], ['display_name' => 'Super Admin', 'description' => 'Super Admin']);
        Role::firstOrCreate(['name' => 'staff'],       ['display_name' => 'Staff',       'description' => 'Staff']);
        Role::firstOrCreate(['name' => 'client'],      ['display_name' => 'Client',      'description' => 'Client']);

        Cache::flush();
    }

    protected function makeUserWithRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge(['is_active' => true], $attributes));
        $user->assignRole($role);

        return $user;
    }

    protected function setEmailPreference(User $user, bool $mute_all, array $disabled_email_types = []): void
    {
        UserEmailPreference::updateOrCreate(
            ['user_id' => $user->id],
            [
                'mute_all_emails'      => $mute_all,
                'disabled_email_types' => $disabled_email_types,
            ],
        );

        EmailDeliveryService::invalidateCache();
    }

    protected function makeClientCommentReplyMailable(string $email): ClientCommentReplyNotification
    {
        return new ClientCommentReplyNotification(
            client_name: 'Client',
            client_email: $email,
            order_id: 'order-1',
            order_title: 'Sample Order',
            original_comment_content: 'Original comment',
            original_comment_date: 'Jan 1, 2026',
            reply_content: 'Reply',
            reply_date: 'Jan 2, 2026',
            admin_name: 'Admin Person',
            admin_initials: 'AP',
            view_reply_url: 'https://example.com',
        );
    }

    protected function makeAdminCommentMailable(string $email): AdminOrderCommentNotification
    {
        return new AdminOrderCommentNotification(
            recipient_name: 'Admin',
            recipient_email: $email,
            client_name: 'Client',
            client_email: 'client@example.com',
            client_initials: 'CL',
            order_id: 'order-1',
            order_title: 'Sample Order',
            comment_content: 'A comment',
            comment_date: 'Jan 1, 2026',
            view_comment_url: 'https://example.com',
            settings_url: 'https://example.com/settings',
        );
    }

    protected function makeSymfonyMessage(array $to, array $cc = [], array $bcc = []): Email
    {
        $message = new Email();
        $message->to(...$to);

        if (! empty($cc)) {
            $message->cc(...$cc);
        }

        if (! empty($bcc)) {
            $message->bcc(...$bcc);
        }

        $message->subject('Subject');
        $message->html('<p>Body</p>');

        return $message;
    }

    /**
     * Messages actually handed to the transport, which is what reached the
     * recipient. A message cancelled by the delivery policy never shows up here.
     */
    protected function sentMessages(): array
    {
        return app('mailer')->getSymfonyTransport()->messages()->all();
    }

    protected function sentRecipientEmails(): array
    {
        $emails = [];

        foreach ($this->sentMessages() as $sent_message) {
            foreach ($sent_message->getOriginalMessage()->getTo() as $address) {
                $emails[] = $address->getAddress();
            }
        }

        return $emails;
    }
}
