<?php

namespace Tests\Feature\EmailNotification;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Every email must leave through Laravel's Mailer with a registered Mailable
 * or mail Notification, since that is the only path that fires MessageSending
 * and therefore the only path the Email Notification Settings can control.
 * This test fails if code sends mail any other way: PHP's mail(), raw or
 * inline mail without a Mailable class, a hand built Symfony mailer, or a
 * direct HTTP call to an email provider's API.
 */
class EmailSendingArchitectureTest extends TestCase
{
    private const FORBIDDEN_PATTERNS = [
        '/(?<![\w>:$])mail\s*\(/i'                                  => 'PHP mail()',
        '/Mail::(raw|html|plain)\s*\(/'                              => 'Mail::raw/html/plain without a Mailable',
        '/->(raw|html|plain)\s*\(\s*[\'"$]/'                         => 'mailer raw/html/plain without a Mailable',
        '/Symfony\\\\Component\\\\Mailer\\\\(Mailer|Transport)\b/'   => 'a hand built Symfony mailer',
        '/Transport::fromDsn\s*\(/'                                  => 'a hand built mail transport',
        '/https?:\/\/api\.(mailgun\.net|sendgrid\.com|postmarkapp\.com|resend\.com|brevo\.com)/i' => 'a direct email provider API call',
    ];

    public function test_application_code_only_sends_email_through_laravel_mailables(): void
    {
        $violations = [];

        foreach (Finder::create()->files()->in([app_path(), base_path('routes')])->name('*.php') as $file) {
            foreach (file($file->getRealPath()) as $line_number => $line) {
                $code = preg_replace('/(\/\/|#).*$/', '', $line);

                foreach (self::FORBIDDEN_PATTERNS as $pattern => $description) {
                    if (preg_match($pattern, $code)) {
                        $violations[] = sprintf('%s:%d uses %s', $file->getRelativePathname(), $line_number + 1, $description);
                    }
                }
            }
        }

        $this->assertSame([], $violations, "Send these emails through a Mailable registered in EmailTypeCatalog:\n" . implode("\n", $violations));
    }
}
