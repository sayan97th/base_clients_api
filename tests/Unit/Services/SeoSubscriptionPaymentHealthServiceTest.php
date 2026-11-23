<?php

namespace Tests\Unit\Services;

use App\Services\SeoSubscriptionPaymentHealthService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The plain language pieces of the payment health service: the reason a
 * client is given for a decline, and the balance line admins read in emails.
 */
class SeoSubscriptionPaymentHealthServiceTest extends TestCase
{
    public static function declineReasonProvider(): array
    {
        return [
            'no card'              => ['no_payment_method', 'There is no card on file for your account.'],
            'expired'              => ['expired_card', 'The card on file has expired.'],
            'needs authentication' => ['authentication_required', 'Your bank asked for extra verification for this payment.'],
            'no funds'             => ['insufficient_funds', 'Your bank declined the payment due to insufficient funds.'],
            'lost'                 => ['lost_card', 'Your bank declined the card on file.'],
            'stolen'               => ['stolen_card', 'Your bank declined the card on file.'],
            'pickup'               => ['pickup_card', 'Your bank declined the card on file.'],
            'restricted'           => ['restricted_card', 'Your bank declined the card on file.'],
            'wrong number'         => ['incorrect_number', 'The card on file is no longer valid.'],
            'invalid method'       => ['invalid_payment_method', 'The card on file is no longer valid.'],
            'invalid account'      => ['invalid_account', 'The card on file is no longer valid.'],
            'generic decline'      => ['card_declined', 'Your bank declined the payment.'],
            'unknown code'         => ['something_new', 'Your bank declined the payment.'],
            'no code'              => [null, 'Your bank declined the payment.'],
        ];
    }

    #[DataProvider('declineReasonProvider')]
    public function test_every_decline_code_has_a_client_friendly_reason(?string $code, string $reason): void
    {
        $this->assertSame($reason, SeoSubscriptionPaymentHealthService::clientFacingFailureReason($code));
    }

    public function test_client_reasons_never_leak_codes_or_use_em_dashes(): void
    {
        foreach (self::declineReasonProvider() as [$code]) {
            $reason = SeoSubscriptionPaymentHealthService::clientFacingFailureReason($code);

            $this->assertStringNotContainsString('_', $reason);
            $this->assertStringNotContainsString('—', $reason);
            $this->assertStringEndsWith('.', $reason);
        }
    }

    public function test_the_outstanding_label_is_empty_when_nothing_is_owed(): void
    {
        $this->assertSame('', SeoSubscriptionPaymentHealthService::outstandingLabel(['overdue_periods' => 0, 'overdue_amount' => 0]));
        $this->assertSame('', SeoSubscriptionPaymentHealthService::outstandingLabel([]));
    }

    public function test_the_outstanding_label_reads_naturally(): void
    {
        $this->assertSame(
            '1 month behind, $2,000.00 outstanding',
            SeoSubscriptionPaymentHealthService::outstandingLabel(['overdue_periods' => 1, 'overdue_amount' => 2000])
        );
        $this->assertSame(
            '3 months behind, $4,498.50 outstanding',
            SeoSubscriptionPaymentHealthService::outstandingLabel(['overdue_periods' => 3, 'overdue_amount' => 4498.5])
        );
    }
}
