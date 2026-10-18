<?php

namespace Tests\Unit\Support\Email;

use App\Mail\InterceptedEmailCopy;
use App\Mail\NotificationEmail;
use App\Mail\ServiceVerificationEmail;
use App\Mail\TestEmail;
use App\Notifications\ResetPasswordNotification;
use App\Support\Email\EmailTypeCatalog;
use Tests\TestCase;

class EmailTypeCatalogTest extends TestCase
{
    /**
     * Mirrors the "type" enum of the notifications table. Every portal
     * notification type must map to an email type, or its email would be
     * delivered without any policy check.
     */
    private const PORTAL_NOTIFICATION_TYPES = [
        'payment',
        'post',
        'system',
        'order',
        'user_registration',
        'order_comment',
        'invoice',
        'ticket',
    ];

    public function test_email_type_keys_are_snake_case(): void
    {
        foreach (EmailTypeCatalog::keys() as $key) {
            $this->assertMatchesRegularExpression('/^[a-z]+(_[a-z]+)*$/', $key);
        }
    }

    public function test_every_email_type_is_fully_described(): void
    {
        $valid_audiences = [
            EmailTypeCatalog::AUDIENCE_ADMIN,
            EmailTypeCatalog::AUDIENCE_CLIENT,
            EmailTypeCatalog::AUDIENCE_ANY,
        ];

        foreach (EmailTypeCatalog::all() as $key => $email_type) {
            $this->assertSame($key, $email_type['key']);
            $this->assertNotEmpty($email_type['label'], "{$key} needs a label");
            $this->assertNotEmpty($email_type['description'], "{$key} needs a description");
            $this->assertContains($email_type['audience'], $valid_audiences, "{$key} has an unknown audience");
            $this->assertArrayHasKey($email_type['category'], EmailTypeCatalog::CATEGORIES, "{$key} has an unknown category");
            $this->assertNotEmpty($email_type['classes'], "{$key} must map to at least one class");

            foreach ($email_type['classes'] as $class) {
                $this->assertTrue(class_exists($class), "{$key} references a missing class {$class}");
            }
        }
    }

    public function test_user_facing_text_does_not_use_em_dashes(): void
    {
        foreach (EmailTypeCatalog::all() as $email_type) {
            $this->assertStringNotContainsString('—', $email_type['label']);
            $this->assertStringNotContainsString('—', $email_type['description']);
        }

        foreach (EmailTypeCatalog::CATEGORIES as $label) {
            $this->assertStringNotContainsString('—', $label);
        }
    }

    public function test_every_class_except_notification_email_maps_to_a_single_email_type(): void
    {
        $owners = [];

        foreach (EmailTypeCatalog::all() as $key => $email_type) {
            foreach ($email_type['classes'] as $class) {
                if ($class !== NotificationEmail::class) {
                    $owners[$class][] = $key;
                }
            }
        }

        foreach ($owners as $class => $keys) {
            $this->assertCount(1, $keys, "{$class} is registered under several email types: " . implode(', ', $keys));
        }
    }

    public function test_every_portal_notification_type_resolves_to_exactly_one_email_type(): void
    {
        foreach (self::PORTAL_NOTIFICATION_TYPES as $notification_type) {
            $matches = array_filter(
                EmailTypeCatalog::all(),
                fn (array $email_type) => in_array($notification_type, $email_type['notification_types'], true),
            );

            $this->assertCount(1, $matches, "Portal notification type '{$notification_type}' must map to exactly one email type");
            $this->assertNotNull(EmailTypeCatalog::resolveKey(NotificationEmail::class, $notification_type));
        }
    }

    public function test_notification_email_without_a_known_type_is_not_resolved(): void
    {
        $this->assertNull(EmailTypeCatalog::resolveKey(NotificationEmail::class, 'not_a_type'));
        $this->assertNull(EmailTypeCatalog::resolveKey(NotificationEmail::class));
        $this->assertNull(EmailTypeCatalog::resolveKey(null));
    }

    public function test_exempt_classes_are_never_registered_as_email_types(): void
    {
        $this->assertTrue(EmailTypeCatalog::isExemptClass(TestEmail::class));
        $this->assertTrue(EmailTypeCatalog::isExemptClass(ServiceVerificationEmail::class));
        $this->assertTrue(EmailTypeCatalog::isExemptClass(InterceptedEmailCopy::class));
        $this->assertFalse(EmailTypeCatalog::isExemptClass(null));

        foreach (EmailTypeCatalog::EXEMPT_CLASSES as $class) {
            $this->assertNull(EmailTypeCatalog::resolveKey($class));
        }
    }

    public function test_account_and_access_emails_are_mandatory(): void
    {
        foreach (EmailTypeCatalog::all() as $email_type) {
            if ($email_type['category'] === EmailTypeCatalog::CATEGORY_ACCOUNT) {
                $this->assertTrue($email_type['is_mandatory'], "{$email_type['key']} must always be delivered");
            }
        }

        $this->assertSame('password_reset', EmailTypeCatalog::resolveKey(ResetPasswordNotification::class));
    }

    public function test_configurable_keys_exclude_every_mandatory_type(): void
    {
        $configurable_keys = EmailTypeCatalog::configurableKeys();

        $this->assertNotEmpty($configurable_keys);

        foreach (EmailTypeCatalog::all() as $key => $email_type) {
            $this->assertSame(! $email_type['is_mandatory'], in_array($key, $configurable_keys, true));
        }
    }

    public function test_admin_audience_list_never_includes_client_only_types(): void
    {
        $admin_types = EmailTypeCatalog::forAdminAudience();

        $this->assertArrayHasKey('admin_credit_purchase', $admin_types);
        $this->assertArrayHasKey('portal_payment_notification', $admin_types);

        foreach ($admin_types as $email_type) {
            $this->assertNotSame(EmailTypeCatalog::AUDIENCE_CLIENT, $email_type['audience']);
        }
    }

    public function test_category_list_keeps_the_declared_order(): void
    {
        $this->assertSame(
            array_keys(EmailTypeCatalog::CATEGORIES),
            array_column(EmailTypeCatalog::categoryList(), 'key'),
        );
    }

    public function test_managed_classes_include_exempt_and_registered_classes_without_duplicates(): void
    {
        $managed_classes = EmailTypeCatalog::managedClasses();

        $this->assertSame(array_values(array_unique($managed_classes)), $managed_classes);
        $this->assertContains(TestEmail::class, $managed_classes);
        $this->assertContains(NotificationEmail::class, $managed_classes);
    }
}
