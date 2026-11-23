<?php

namespace Tests\Feature\Mail;

use App\Mail\AdminSeoSubscriptionBillingNotification;
use App\Mail\SeoSubscriptionBillingEmail;
use App\Support\Email\EmailTypeCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Client and admin SEO subscription emails: every variant renders, says
 * clearly that the charge is for the SEO subscription, links to the right
 * place, never contains an em dash, and is registered in the email catalog.
 */
class SeoSubscriptionEmailsTest extends TestCase
{
    private function clientEmail(string $variant, array $overrides = []): SeoSubscriptionBillingEmail
    {
        $data = array_merge([
            'variant'           => $variant,
            'client_first_name' => 'Cathleen',
            'client_email'      => 'cathleen@example.com',
            'plan_name'         => 'Custom SEO Package',
            'amount'            => '$2,000.00',
            'period_label'      => 'December 2026',
            'period_range'      => 'Dec 1, 2026 to Dec 31, 2026',
            'billing_date'      => 'December 1, 2026',
            'next_billing_date' => 'January 1, 2027',
            'card_brand'        => 'visa',
            'card_label'        => 'Visa ending in 4242',
            'invoice_number'    => 'BSM-1042',
            'invoice_url'       => 'https://portal.test/invoices/ABC123',
            'failure_reason'    => 'Your bank declined the payment due to insufficient funds.',
            'next_retry_date'   => 'December 4, 2026',
            'paid_at'           => 'December 1, 2026',
            'billing_url'       => 'https://portal.test/billing',
            'support_url'       => 'https://portal.test/support',
        ], $overrides);

        return new SeoSubscriptionBillingEmail(...$data);
    }

    private function adminEmail(string $variant, array $overrides = []): AdminSeoSubscriptionBillingNotification
    {
        $data = array_merge([
            'variant'           => $variant,
            'recipient_name'    => 'Abigail',
            'recipient_email'   => 'abigail@example.com',
            'client_name'       => 'Cathleen Siech',
            'client_email'      => 'cathleen@example.com',
            'client_company'    => 'Printed Image',
            'client_initials'   => 'CS',
            'plan_name'         => 'Custom SEO Package',
            'is_custom_price'   => true,
            'amount'            => '$2,000.00',
            'period_label'      => 'December 2026',
            'period_range'      => 'Dec 1, 2026 to Dec 31, 2026',
            'card_label'        => 'Visa ending in 4242',
            'trigger_label'     => 'Automatic renewal',
            'attempt_label'     => 'Attempt 2',
            'invoice_number'    => 'BSM-1042',
            'failure_reason'    => 'Insufficient funds.',
            'next_retry_date'   => 'December 9, 2026',
            'next_billing_date' => 'January 1, 2027',
            'auto_renew'        => true,
            'occurred_at'       => 'December 1, 2026 at 9:05 AM',
            'subscription_url'  => 'https://portal.test/admin/seo-packages-subscriptions/7',
            'invoice_url'       => 'https://portal.test/admin/invoices/abc',
            'settings_url'      => 'https://portal.test/admin/email-notifications',
        ], $overrides);

        return new AdminSeoSubscriptionBillingNotification(...$data);
    }

    // ── Client emails ───────────────────────────────────────────────────────

    public static function clientVariantProvider(): array
    {
        return [
            'receipt'  => [SeoSubscriptionBillingEmail::VARIANT_PAYMENT_SUCCEEDED, 'Payment received for your SEO subscription, December 2026', 'View invoice'],
            'failed'   => [SeoSubscriptionBillingEmail::VARIANT_PAYMENT_FAILED, 'Action needed: your SEO subscription payment did not go through', 'Pay invoice now'],
            'reminder' => [SeoSubscriptionBillingEmail::VARIANT_RENEWAL_REMINDER, 'Your SEO subscription renews on December 1, 2026', 'Review payment method'],
            'no card'  => [SeoSubscriptionBillingEmail::VARIANT_PAYMENT_METHOD_MISSING, 'Add a card to keep your SEO subscription running', 'Add a payment method'],
        ];
    }

    #[DataProvider('clientVariantProvider')]
    public function test_client_email_variants_render_with_their_subject_and_action(string $variant, string $subject, string $action): void
    {
        $mail = $this->clientEmail($variant);
        $html = $mail->render();

        $this->assertStringStartsWith($subject, $mail->envelope()->subject);
        $this->assertStringContainsString($action, $html);
        $this->assertStringContainsString('Custom SEO Package', $html);
        $this->assertStringContainsString('$2,000.00', $html);
        $this->assertStringContainsString('Hi Cathleen', $html);
        $this->assertStringContainsString('viewport', $html, 'Built for phones.');
        $this->assertStringNotContainsString('—', $html);
    }

    public function test_receipt_explains_the_charge_is_the_monthly_subscription(): void
    {
        $html = $this->clientEmail(SeoSubscriptionBillingEmail::VARIANT_PAYMENT_SUCCEEDED)->render();

        $this->assertStringContainsString('What is this charge?', $html);
        $this->assertStringContainsString('monthly payment for your Custom SEO Package subscription', $html);
        $this->assertStringContainsString('January 1, 2027', $html);
        $this->assertStringContainsString('https://portal.test/invoices/ABC123', $html);
    }

    public function test_failed_email_gives_the_reason_retry_date_and_both_ways_to_fix_it(): void
    {
        $html = $this->clientEmail(SeoSubscriptionBillingEmail::VARIANT_PAYMENT_FAILED)->render();

        $this->assertStringContainsString('insufficient funds', $html);
        $this->assertStringContainsString('December 4, 2026', $html);
        $this->assertStringContainsString('https://portal.test/invoices/ABC123', $html);
        $this->assertStringContainsString('Update card', $html);
        $this->assertStringContainsString('https://portal.test/billing', $html);
    }

    public function test_failed_email_says_a_new_card_is_retried_right_away(): void
    {
        $html = $this->clientEmail(SeoSubscriptionBillingEmail::VARIANT_PAYMENT_FAILED)->render();
        $this->assertStringContainsString('As soon as you add a new card we try again automatically, at the latest on December 4, 2026.', $html);

        $html = $this->clientEmail(SeoSubscriptionBillingEmail::VARIANT_PAYMENT_FAILED, ['next_retry_date' => ''])->render();
        $this->assertStringContainsString('Once you add a new card or pay the invoice, your subscription continues as usual.', $html);
    }

    public function test_failed_email_falls_back_to_the_billing_page_without_an_invoice(): void
    {
        $html = $this->clientEmail(SeoSubscriptionBillingEmail::VARIANT_PAYMENT_FAILED, ['invoice_url' => '', 'invoice_number' => ''])->render();

        $this->assertStringContainsString('https://portal.test/billing', $html);
        $this->assertStringNotContainsString('Invoice</td>', $html);
    }

    public function test_reminder_tells_the_client_there_is_nothing_to_do(): void
    {
        $html = $this->clientEmail(SeoSubscriptionBillingEmail::VARIANT_RENEWAL_REMINDER)->render();

        $this->assertStringContainsString('you do not need to do anything', $html);
        $this->assertStringContainsString('Visa ending in 4242', $html);
        $this->assertStringNotContainsString('Pay invoice now', $html);
    }

    public function test_card_missing_email_explains_nothing_was_charged_and_links_to_billing(): void
    {
        $html = $this->clientEmail(SeoSubscriptionBillingEmail::VARIANT_PAYMENT_METHOD_MISSING, ['card_label' => ''])->render();

        $this->assertStringContainsString('Card needed', $html);
        $this->assertStringContainsString('No card on file', $html);
        $this->assertStringContainsString('Nothing has been charged', $html);
        $this->assertStringContainsString('December 1, 2026', $html);
        $this->assertStringContainsString('https://portal.test/billing', $html);
        $this->assertStringNotContainsString('Pay invoice now', $html);
    }

    // ── Admin emails ────────────────────────────────────────────────────────

    public function test_admin_payment_received_email(): void
    {
        $mail = $this->adminEmail(AdminSeoSubscriptionBillingNotification::VARIANT_PAYMENT_SUCCEEDED, ['failure_reason' => '', 'next_retry_date' => '']);
        $html = $mail->render();

        $this->assertStringContainsString('Cathleen Siech paid $2,000.00 for their SEO subscription (December 2026)', $mail->envelope()->subject);
        $this->assertStringContainsString('Payment collected from Cathleen Siech', $html);
        $this->assertStringContainsString('Printed Image', $html);
        $this->assertStringContainsString('custom price', $html);
        $this->assertStringContainsString('January 1, 2027 (auto renew)', $html);
        $this->assertStringContainsString('Open subscription', $html);
        $this->assertStringContainsString('Email Notification Settings', $html);
        $this->assertStringNotContainsString('Next steps', $html);
        $this->assertStringNotContainsString('—', $html);
    }

    public function test_admin_payment_failed_email_lists_the_next_steps(): void
    {
        $mail = $this->adminEmail(AdminSeoSubscriptionBillingNotification::VARIANT_PAYMENT_FAILED);
        $html = $mail->render();

        $this->assertStringContainsString('SEO subscription payment failed for Cathleen Siech', $mail->envelope()->subject);
        $this->assertStringContainsString('Insufficient funds.', $html);
        $this->assertStringContainsString('Attempt 2', $html);
        $this->assertStringContainsString('December 9, 2026', $html);
        $this->assertStringContainsString('Next steps', $html);
        $this->assertStringNotContainsString('—', $html);
    }

    public function test_admin_failed_email_says_when_retries_have_stopped(): void
    {
        $html = $this->adminEmail(AdminSeoSubscriptionBillingNotification::VARIANT_PAYMENT_FAILED, ['next_retry_date' => ''])->render();

        $this->assertStringContainsString('Automatic retries have stopped', $html);
    }

    public function test_admin_failed_email_shows_how_far_behind_the_client_is(): void
    {
        $html = $this->adminEmail(AdminSeoSubscriptionBillingNotification::VARIANT_PAYMENT_FAILED, [
            'outstanding_label' => '2 months behind, $4,000.00 outstanding',
        ])->render();

        $this->assertStringContainsString('2 months behind, $4,000.00 outstanding', $html);
        $this->assertStringContainsString('Outstanding', $html);
    }

    public function test_admin_card_removed_email(): void
    {
        $mail = $this->adminEmail(AdminSeoSubscriptionBillingNotification::VARIANT_PAYMENT_METHOD_REMOVED, [
            'card_label'      => 'No card on file',
            'trigger_label'   => 'Card removed by the client',
            'attempt_label'   => '',
            'invoice_number'  => '',
            'invoice_url'     => '',
            'next_retry_date' => '',
            'failure_reason'  => 'The client has no valid card on file.',
        ]);
        $html = $mail->render();

        $this->assertStringContainsString('Cathleen Siech removed their last card', $mail->envelope()->subject);
        $this->assertStringContainsString('Cathleen Siech has no card on file', $html);
        $this->assertStringContainsString('Card needed', $html);
        $this->assertStringContainsString('What this means.', $html);
        $this->assertStringContainsString('Cancel if they are leaving', $html);
        $this->assertStringNotContainsString('Attempt', $html);
        $this->assertStringNotContainsString('—', $html);
    }

    public function test_admin_email_hides_the_invoice_button_without_an_invoice(): void
    {
        $html = $this->adminEmail(AdminSeoSubscriptionBillingNotification::VARIANT_PAYMENT_FAILED, ['invoice_url' => '', 'invoice_number' => ''])->render();

        $this->assertStringNotContainsString('View invoice', $html);
    }

    // ── Catalog ─────────────────────────────────────────────────────────────

    public function test_both_emails_are_registered_in_the_email_catalog(): void
    {
        $client_type = EmailTypeCatalog::find('seo_subscription_billing');
        $admin_type  = EmailTypeCatalog::find('admin_seo_subscription_billing');

        $this->assertSame([SeoSubscriptionBillingEmail::class], $client_type['classes']);
        $this->assertSame(EmailTypeCatalog::AUDIENCE_CLIENT, $client_type['audience']);
        $this->assertTrue($client_type['is_mandatory'], 'Clients always get their billing emails.');

        $this->assertSame([AdminSeoSubscriptionBillingNotification::class], $admin_type['classes']);
        $this->assertSame(EmailTypeCatalog::AUDIENCE_ADMIN, $admin_type['audience']);
        $this->assertContains('admin_seo_subscription_billing', EmailTypeCatalog::configurableKeys(), 'Admins can turn it off in Email Notification Settings.');

        $this->assertSame('seo_subscription_billing', EmailTypeCatalog::resolveKey(SeoSubscriptionBillingEmail::class));
        $this->assertSame('admin_seo_subscription_billing', EmailTypeCatalog::resolveKey(AdminSeoSubscriptionBillingNotification::class));
    }
}
