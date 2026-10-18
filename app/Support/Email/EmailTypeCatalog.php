<?php

namespace App\Support\Email;

use App\Mail\AdminCreditPurchaseNotification;
use App\Mail\AdminInvoicePaidNotification;
use App\Mail\AdminInvoiceRefundedNotification;
use App\Mail\AdminNewTicketNotification;
use App\Mail\AdminOrderCommentNotification;
use App\Mail\AdminPayLaterOrderNotification;
use App\Mail\AdminTicketMessageNotification;
use App\Mail\ClientCommentReplyNotification;
use App\Mail\ClientInvitationMail;
use App\Mail\ClientPlatformWelcomeEmail;
use App\Mail\ClientWelcomeEmail;
use App\Mail\CreditPurchaseConfirmationMail;
use App\Mail\FeatureAnnouncementEmail;
use App\Mail\InterceptedEmailCopy;
use App\Mail\InvoiceRefundedNotification;
use App\Mail\NotificationEmail;
use App\Mail\OrderReportMail;
use App\Mail\OrderStatusChangeMail;
use App\Mail\OrderUpdateMail;
use App\Mail\PaymentSuccessfulEmail;
use App\Mail\ServiceVerificationEmail;
use App\Mail\StaffInvitationMail;
use App\Mail\TeamInvitationMail;
use App\Mail\TestEmail;
use App\Mail\TicketReplyClientNotification;
use App\Notifications\InvoiceCreatedNotification;
use App\Notifications\InvoiceReminderNotification;
use App\Notifications\InvoiceUpdatedNotification;
use App\Notifications\ResetPasswordNotification;

/**
 * Single source of truth for every kind of email the platform can send.
 *
 * Every Mailable and mail Notification is mapped to exactly one email type
 * here, and the delivery policy (EmailDeliveryService) uses that type to
 * decide whether a given recipient is allowed to receive it. Adding a new
 * email to the codebase means registering its class here; the test suite
 * fails if a Mailable is left unregistered, so nothing can bypass the
 * Email Notification Settings by accident.
 *
 * Mandatory types are always delivered, neither a global toggle nor a
 * personal opt out can block them. That covers two groups:
 *   - Account and access emails (password resets, invitations, welcome
 *     emails), since they grant or recover access to an account.
 *   - Every client side email. Clients must always receive the emails about
 *     their own orders, payments and tickets, so the Email Notification
 *     Settings only ever control what reaches the admin side.
 */
final class EmailTypeCatalog
{
    public const AUDIENCE_ADMIN  = 'admin';
    public const AUDIENCE_CLIENT = 'client';
    public const AUDIENCE_ANY    = 'any';

    public const CATEGORY_PURCHASES     = 'purchases';
    public const CATEGORY_BILLING       = 'billing';
    public const CATEGORY_ORDERS        = 'orders';
    public const CATEGORY_SUPPORT       = 'support';
    public const CATEGORY_ANNOUNCEMENTS = 'announcements';
    public const CATEGORY_ACCOUNT       = 'account';

    public const CATEGORIES = [
        self::CATEGORY_PURCHASES     => 'Purchases and payments',
        self::CATEGORY_BILLING       => 'Invoices and refunds',
        self::CATEGORY_ORDERS        => 'Orders and comments',
        self::CATEGORY_SUPPORT       => 'Support tickets',
        self::CATEGORY_ANNOUNCEMENTS => 'Announcements and system alerts',
        self::CATEGORY_ACCOUNT       => 'Account and access',
    ];

    /**
     * Test, diagnostic and interceptor copy emails. These are never subject
     * to the delivery policy: they are only sent on purpose by a developer
     * or an admin, or are copies the interceptor was explicitly told to send.
     */
    public const EXEMPT_CLASSES = [
        TestEmail::class,
        ServiceVerificationEmail::class,
        InterceptedEmailCopy::class,
    ];

    private const EMAIL_TYPES = [
        // ── Admin side ──────────────────────────────────────────────────────
        'admin_credit_purchase' => [
            'label'       => 'Client credit purchase',
            'description' => 'Sent to admins when a client buys a credit package.',
            'audience'    => self::AUDIENCE_ADMIN,
            'category'    => self::CATEGORY_PURCHASES,
            'classes'     => [AdminCreditPurchaseNotification::class],
        ],
        'admin_invoice_paid' => [
            'label'       => 'Invoice paid',
            'description' => 'Sent to admins when a client pays an invoice or completes a checkout.',
            'audience'    => self::AUDIENCE_ADMIN,
            'category'    => self::CATEGORY_PURCHASES,
            'classes'     => [AdminInvoicePaidNotification::class],
        ],
        'admin_pay_later_order' => [
            'label'       => 'Pay later order placed',
            'description' => 'Sent to admins when a client places an order with the pay later option.',
            'audience'    => self::AUDIENCE_ADMIN,
            'category'    => self::CATEGORY_PURCHASES,
            'classes'     => [AdminPayLaterOrderNotification::class],
        ],
        'admin_invoice_refunded' => [
            'label'       => 'Invoice refunded',
            'description' => 'Sent to admins when a full or partial refund is issued on an invoice.',
            'audience'    => self::AUDIENCE_ADMIN,
            'category'    => self::CATEGORY_BILLING,
            'classes'     => [AdminInvoiceRefundedNotification::class],
        ],
        'admin_order_comment' => [
            'label'       => 'New order comment',
            'description' => 'Sent to admins when a client comments on one of their orders.',
            'audience'    => self::AUDIENCE_ADMIN,
            'category'    => self::CATEGORY_ORDERS,
            'classes'     => [AdminOrderCommentNotification::class],
        ],
        'admin_new_ticket' => [
            'label'       => 'New support ticket',
            'description' => 'Sent to admins when a client opens a new support ticket.',
            'audience'    => self::AUDIENCE_ADMIN,
            'category'    => self::CATEGORY_SUPPORT,
            'classes'     => [AdminNewTicketNotification::class],
        ],
        'admin_ticket_message' => [
            'label'       => 'Client ticket reply',
            'description' => 'Sent to admins when a client replies to a support ticket.',
            'audience'    => self::AUDIENCE_ADMIN,
            'category'    => self::CATEGORY_SUPPORT,
            'classes'     => [AdminTicketMessageNotification::class],
        ],

        // ── Portal notification emails (admin and client side) ──────────────
        'portal_payment_notification' => [
            'label'              => 'Payment receipt notification',
            'description'        => 'Email copy of the portal "Payment Receipt" alert, sent to the paying client and to admin recipients.',
            'audience'           => self::AUDIENCE_ANY,
            'category'           => self::CATEGORY_PURCHASES,
            'classes'            => [NotificationEmail::class],
            'notification_types' => ['payment'],
        ],
        'portal_order_notification' => [
            'label'              => 'Order activity notification',
            'description'        => 'Email copy of portal alerts about order activity, such as new link building orders.',
            'audience'           => self::AUDIENCE_ANY,
            'category'           => self::CATEGORY_ORDERS,
            'classes'            => [NotificationEmail::class],
            'notification_types' => ['order', 'order_comment'],
        ],
        'portal_invoice_notification' => [
            'label'              => 'Invoice activity notification',
            'description'        => 'Email copy of portal alerts about invoices being created or updated.',
            'audience'           => self::AUDIENCE_ANY,
            'category'           => self::CATEGORY_BILLING,
            'classes'            => [NotificationEmail::class],
            'notification_types' => ['invoice'],
        ],
        'portal_ticket_notification' => [
            'label'              => 'Support ticket notification',
            'description'        => 'Email copy of portal alerts about support ticket activity.',
            'audience'           => self::AUDIENCE_ANY,
            'category'           => self::CATEGORY_SUPPORT,
            'classes'            => [NotificationEmail::class],
            'notification_types' => ['ticket'],
        ],
        'portal_post_notification' => [
            'label'              => 'News post notification',
            'description'        => 'Email copy of portal alerts when a new post or update is published.',
            'audience'           => self::AUDIENCE_ANY,
            'category'           => self::CATEGORY_ANNOUNCEMENTS,
            'classes'            => [NotificationEmail::class],
            'notification_types' => ['post'],
        ],
        'portal_system_notification' => [
            'label'              => 'System alert notification',
            'description'        => 'Email copy of portal system alerts and new user registration alerts.',
            'audience'           => self::AUDIENCE_ANY,
            'category'           => self::CATEGORY_ANNOUNCEMENTS,
            'classes'            => [NotificationEmail::class],
            'notification_types' => ['system', 'user_registration'],
        ],

        // ── Client side ─────────────────────────────────────────────────────
        'payment_successful' => [
            'label'       => 'Payment confirmed',
            'description' => 'Sent to the client after a payment is processed successfully.',
            'audience'    => self::AUDIENCE_CLIENT,
            'category'    => self::CATEGORY_PURCHASES,
            'classes'     => [PaymentSuccessfulEmail::class],
        ],
        'credit_purchase_confirmation' => [
            'label'       => 'Credit purchase confirmation',
            'description' => 'Sent to the client when purchased credits are added to their account.',
            'audience'    => self::AUDIENCE_CLIENT,
            'category'    => self::CATEGORY_PURCHASES,
            'classes'     => [CreditPurchaseConfirmationMail::class],
        ],
        'invoice_created' => [
            'label'       => 'Invoice created',
            'description' => 'Sent to the client when an admin issues a new invoice.',
            'audience'    => self::AUDIENCE_CLIENT,
            'category'    => self::CATEGORY_BILLING,
            'classes'     => [InvoiceCreatedNotification::class],
        ],
        'invoice_updated' => [
            'label'       => 'Invoice updated',
            'description' => 'Sent to the client when an admin changes an existing invoice.',
            'audience'    => self::AUDIENCE_CLIENT,
            'category'    => self::CATEGORY_BILLING,
            'classes'     => [InvoiceUpdatedNotification::class],
        ],
        'invoice_reminder' => [
            'label'       => 'Invoice reminder',
            'description' => 'Sent to the client when an admin sends a payment reminder for an open invoice.',
            'audience'    => self::AUDIENCE_CLIENT,
            'category'    => self::CATEGORY_BILLING,
            'classes'     => [InvoiceReminderNotification::class],
        ],
        'invoice_refunded' => [
            'label'       => 'Invoice refunded (client copy)',
            'description' => 'Sent to the client when a refund is processed on their invoice.',
            'audience'    => self::AUDIENCE_CLIENT,
            'category'    => self::CATEGORY_BILLING,
            'classes'     => [InvoiceRefundedNotification::class],
        ],
        'order_status_change' => [
            'label'       => 'Order status update',
            'description' => 'Sent to the client when their order status changes or an admin notifies them manually.',
            'audience'    => self::AUDIENCE_CLIENT,
            'category'    => self::CATEGORY_ORDERS,
            'classes'     => [OrderStatusChangeMail::class],
        ],
        'order_update' => [
            'label'       => 'Order update posted',
            'description' => 'Sent to the client when an admin posts an update on their order with email enabled.',
            'audience'    => self::AUDIENCE_CLIENT,
            'category'    => self::CATEGORY_ORDERS,
            'classes'     => [OrderUpdateMail::class],
        ],
        'order_report' => [
            'label'       => 'Order report delivered',
            'description' => 'Sent to the client when an admin delivers a completed link building report.',
            'audience'    => self::AUDIENCE_CLIENT,
            'category'    => self::CATEGORY_ORDERS,
            'classes'     => [OrderReportMail::class],
        ],
        'client_comment_reply' => [
            'label'       => 'Comment reply',
            'description' => 'Sent to the client when the BASE team replies to their order comment.',
            'audience'    => self::AUDIENCE_CLIENT,
            'category'    => self::CATEGORY_ORDERS,
            'classes'     => [ClientCommentReplyNotification::class],
        ],
        'ticket_reply_client' => [
            'label'       => 'Support ticket reply',
            'description' => 'Sent to the client when the BASE team replies to their support ticket.',
            'audience'    => self::AUDIENCE_CLIENT,
            'category'    => self::CATEGORY_SUPPORT,
            'classes'     => [TicketReplyClientNotification::class],
        ],
        'feature_announcement' => [
            'label'       => 'Feature announcement',
            'description' => 'Product announcements about new features in the portal.',
            'audience'    => self::AUDIENCE_CLIENT,
            'category'    => self::CATEGORY_ANNOUNCEMENTS,
            'classes'     => [FeatureAnnouncementEmail::class],
        ],

        // ── Account and access (always delivered) ───────────────────────────
        'password_reset' => [
            'label'        => 'Password reset',
            'description'  => 'Sent when someone requests a password reset link.',
            'audience'     => self::AUDIENCE_ANY,
            'category'     => self::CATEGORY_ACCOUNT,
            'classes'      => [ResetPasswordNotification::class],
            'is_mandatory' => true,
        ],
        'client_welcome' => [
            'label'        => 'Client welcome email',
            'description'  => 'Sent when a client account is created, or when an admin resends the welcome email.',
            'audience'     => self::AUDIENCE_CLIENT,
            'category'     => self::CATEGORY_ACCOUNT,
            'classes'      => [ClientWelcomeEmail::class, ClientPlatformWelcomeEmail::class],
            'is_mandatory' => true,
        ],
        'client_invitation' => [
            'label'        => 'Client invitation',
            'description'  => 'Sent when an admin invites a new client to the portal.',
            'audience'     => self::AUDIENCE_CLIENT,
            'category'     => self::CATEGORY_ACCOUNT,
            'classes'      => [ClientInvitationMail::class],
            'is_mandatory' => true,
        ],
        'team_invitation' => [
            'label'        => 'Team invitation',
            'description'  => 'Sent when a client invites someone to join their team.',
            'audience'     => self::AUDIENCE_CLIENT,
            'category'     => self::CATEGORY_ACCOUNT,
            'classes'      => [TeamInvitationMail::class],
            'is_mandatory' => true,
        ],
        'staff_invitation' => [
            'label'        => 'Staff invitation',
            'description'  => 'Sent when an admin invites a new member to the admin side.',
            'audience'     => self::AUDIENCE_ADMIN,
            'category'     => self::CATEGORY_ACCOUNT,
            'classes'      => [StaffInvitationMail::class],
            'is_mandatory' => true,
        ],
    ];

    /**
     * Every registered email type keyed by its identifier, with defaults
     * filled in so consumers never need to null check optional fields.
     */
    public static function all(): array
    {
        $email_types = [];

        foreach (self::EMAIL_TYPES as $key => $definition) {
            $email_types[$key] = [
                'key'                => $key,
                'label'              => $definition['label'],
                'description'        => $definition['description'],
                'audience'           => $definition['audience'],
                'category'           => $definition['category'],
                'classes'            => $definition['classes'],
                'notification_types' => $definition['notification_types'] ?? [],
                'is_mandatory'       => ($definition['is_mandatory'] ?? false)
                    || $definition['audience'] === self::AUDIENCE_CLIENT,
            ];
        }

        return $email_types;
    }

    /**
     * Categories as an ordered list, the shape the admin UI consumes.
     */
    public static function categoryList(): array
    {
        return array_map(
            fn (string $key, string $label) => ['key' => $key, 'label' => $label],
            array_keys(self::CATEGORIES),
            self::CATEGORIES,
        );
    }

    public static function find(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    public static function keys(): array
    {
        return array_keys(self::EMAIL_TYPES);
    }

    /**
     * Email types an admin or a recipient is allowed to turn off. Client side
     * types are never included, see the class docblock.
     */
    public static function configurableKeys(): array
    {
        return array_keys(array_filter(
            self::all(),
            fn (array $email_type) => ! $email_type['is_mandatory'],
        ));
    }

    /**
     * Email types that can reach admin side users, used to build the
     * per-user preferences screen.
     */
    public static function forAdminAudience(): array
    {
        return array_filter(
            self::all(),
            fn (array $email_type) => $email_type['audience'] !== self::AUDIENCE_CLIENT,
        );
    }

    public static function isExemptClass(?string $class): bool
    {
        return $class !== null && in_array($class, self::EXEMPT_CLASSES, true);
    }

    /**
     * Maps an outgoing email's class to its registered email type. The
     * generic NotificationEmail is resolved through the portal notification
     * type it carries, since a single class backs several kinds of alerts.
     */
    public static function resolveKey(?string $class, ?string $notification_type = null): ?string
    {
        if ($class === null) {
            return null;
        }

        foreach (self::EMAIL_TYPES as $key => $definition) {
            if (! in_array($class, $definition['classes'], true)) {
                continue;
            }

            $notification_types = $definition['notification_types'] ?? [];

            if (empty($notification_types) || in_array($notification_type, $notification_types, true)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Every class that is either registered to an email type or exempt from
     * the policy. Used by tests to guarantee no Mailable is left unmanaged.
     */
    public static function managedClasses(): array
    {
        $classes = self::EXEMPT_CLASSES;

        foreach (self::EMAIL_TYPES as $definition) {
            array_push($classes, ...$definition['classes']);
        }

        return array_values(array_unique($classes));
    }
}
