<?php

namespace Tests\Feature\GiftCardReward;

use App\Mail\AdminGiftCardRewardNotification;
use Tests\TestCase;

/**
 * Design of the email the admin team receives for each HAPPYHOLIDAYS gift
 * card request. Most recipients read it on their phone.
 */
class AdminGiftCardRewardNotificationMailTest extends TestCase
{
    private function makeMail(array $overrides = []): AdminGiftCardRewardNotification
    {
        return new AdminGiftCardRewardNotification(...array_merge([
            'recipient_name'     => 'Abby',
            'recipient_email'    => 'abby@example.com',
            'client_name'        => 'Jane Client',
            'client_email'       => 'jane@example.com',
            'client_company'     => 'Acme',
            'client_initials'    => 'JC',
            'promo_code'         => 'HAPPYHOLIDAYS',
            'promotion_name'     => 'Happy Holidays 10% Back',
            'gift_card_brand'    => 'amazon',
            'gift_card_label'    => 'Amazon',
            'gift_card_email'    => 'gifts@example.com',
            'reward_amount'      => '$80.00',
            'reward_percentage'  => '10%',
            'order_total'        => '$800.00',
            'order_reference'    => 'ABCD1234',
            'products'           => 'Link Building',
            'is_payment_pending' => false,
            'submitted_at'       => 'December 18, 2026 at 3:00 PM',
            'view_order_url'     => 'https://portal.test/admin/orders/order-1',
            'view_claims_url'    => 'https://portal.test/admin/coupons/coupon-1/edit#gift-card-claims',
            'settings_url'       => 'https://portal.test/admin/email-notifications',
            'promotion_ends_at'  => 'December 31, 2026',
        ], $overrides));
    }

    public function test_subject_tells_the_team_what_to_send_and_to_whom(): void
    {
        $subject = $this->makeMail()->envelope()->subject;

        $this->assertStringContainsString('Send a $80.00 Amazon gift card to Jane Client', $subject);
        $this->assertStringNotContainsString("\u{2014}", $subject);
    }

    public function test_it_shows_everything_needed_to_send_the_gift_card(): void
    {
        $html = $this->makeMail()->render();

        foreach ([
            'Send a $80.00 Amazon gift card',
            'HAPPYHOLIDAYS',
            'mailto:gifts@example.com',
            '10% back on a $800.00 order',
            'Jane Client',
            'jane@example.com',
            'Acme',
            '#ABCD1234',
            'December 18, 2026 at 3:00 PM',
            'Promo ends',
            'December 31, 2026',
            'Hi Abby',
        ] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
    }

    public function test_it_explains_when_the_gift_card_was_capped_by_the_promo_limit(): void
    {
        $html = $this->makeMail([
            'reward_amount' => '$1,000.00',
            'order_total'   => '$50,000.00',
            'reward_cap'    => '$1,000.00',
        ])->render();

        $this->assertStringContainsString('10% back on a $50,000.00 order, capped at $1,000.00', $html);
        $this->assertStringContainsString('capped at the $1,000.00 promo limit', $html);
    }

    public function test_it_has_no_cap_note_when_the_gift_card_was_not_capped(): void
    {
        $this->assertStringNotContainsString('capped at', $this->makeMail()->render());
    }

    public function test_it_lists_the_next_steps_and_links_to_the_portal(): void
    {
        $html = $this->makeMail()->render();

        $this->assertStringContainsString('Next steps', $html);
        $this->assertStringContainsString('Buy the gift card', $html);
        $this->assertStringContainsString('Send it to the client', $html);
        $this->assertStringContainsString('Mark it as sent', $html);
        $this->assertStringContainsString('https://portal.test/admin/coupons/coupon-1/edit#gift-card-claims', $html);
        $this->assertStringContainsString('https://portal.test/admin/orders/order-1', $html);
        $this->assertStringContainsString('https://portal.test/admin/email-notifications', $html);
    }

    public function test_it_is_built_for_mobile(): void
    {
        $html = $this->makeMail()->render();

        $this->assertStringContainsString('name="viewport" content="width=device-width, initial-scale=1.0"', $html);
        $this->assertStringContainsString('@media only screen and (max-width: 600px)', $html);
        $this->assertStringContainsString('class="gc-button"', $html);
        $this->assertStringContainsString('max-width:560px', $html);
    }

    public function test_pay_later_warning_only_shows_for_unpaid_orders(): void
    {
        $this->assertStringNotContainsString('Payment pending.', $this->makeMail()->render());
        $this->assertStringContainsString('Payment pending.', $this->makeMail(['is_payment_pending' => true])->render());
    }

    public function test_promo_end_date_row_is_hidden_for_open_ended_promos(): void
    {
        $this->assertStringNotContainsString('Promo ends', $this->makeMail(['promotion_ends_at' => ''])->render());
    }

    public function test_each_brand_gets_its_own_card_colors(): void
    {
        $this->assertStringContainsString('#232f3e', $this->makeMail()->render());
        $this->assertStringContainsString('#1a1f71', $this->makeMail(['gift_card_brand' => 'visa', 'gift_card_label' => 'Visa'])->render());
        $this->assertStringContainsString('#cc0000', $this->makeMail(['gift_card_brand' => 'target', 'gift_card_label' => 'Target'])->render());
    }

    public function test_greeting_works_without_a_recipient_name(): void
    {
        $html = $this->makeMail(['recipient_name' => ''])->render();

        $this->assertStringContainsString('Hi, a client just told us', $html);
    }

    public function test_copy_never_uses_an_em_dash(): void
    {
        $this->assertStringNotContainsString("\u{2014}", $this->makeMail(['is_payment_pending' => true])->render());
    }
}
