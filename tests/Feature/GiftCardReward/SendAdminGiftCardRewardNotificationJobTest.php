<?php

namespace Tests\Feature\GiftCardReward;

use App\Jobs\SendAdminGiftCardRewardNotificationJob;
use App\Jobs\SendEmailJob;
use App\Mail\AdminGiftCardRewardNotification;
use App\Models\EmailNotificationSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\Support\GiftCardReward\InteractsWithGiftCardRewards;
use Tests\TestCase;

/**
 * "Can Abby and I get notified each time a client submits these details?"
 * The job emails every admin recipient configured in Email Notification
 * Settings once the client has told us which gift card and where to send it.
 */
class SendAdminGiftCardRewardNotificationJobTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithGiftCardRewards;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([SendEmailJob::class]);
        $this->seedGiftCardRewardRoles();
        $this->client = $this->makeUserWithRole('client', [
            'first_name' => 'Jane',
            'last_name'  => 'Client',
            'company'    => 'Acme',
        ]);
    }

    private function runJob(string $claim_id): void
    {
        (new SendAdminGiftCardRewardNotificationJob($claim_id))->handle();
    }

    public function test_the_job_runs_on_the_emails_queue(): void
    {
        $this->assertSame('emails', (new SendAdminGiftCardRewardNotificationJob('id'))->queue);
    }

    public function test_every_admin_is_emailed_when_notify_all_is_enabled(): void
    {
        $kennedy = $this->makeUserWithRole('admin', ['first_name' => 'Kennedy']);
        $abby    = $this->makeUserWithRole('staff', ['first_name' => 'Abby']);
        $this->makeUserWithRole('admin', ['is_active' => false]);
        EmailNotificationSetting::create(['notify_all_admins' => true]);

        $claim = $this->makeSubmittedClaim($this->client, $this->makeRewardCoupon());
        $this->runJob($claim->id);

        foreach ([$kennedy, $abby] as $admin) {
            Bus::assertDispatched(SendEmailJob::class, fn (SendEmailJob $job) => $job->recipient_email === $admin->email
                && $job->mailable instanceof AdminGiftCardRewardNotification);
        }

        Bus::assertDispatched(SendEmailJob::class, 2);
    }

    public function test_only_selected_recipients_and_custom_emails_are_notified(): void
    {
        $selected = $this->makeUserWithRole('admin');
        $excluded = $this->makeUserWithRole('admin');
        EmailNotificationSetting::create([
            'notify_all_admins' => false,
            'enabled_user_ids'  => [$selected->id],
            'custom_emails'     => ['promos@example.com'],
        ]);

        $this->runJob($this->makeSubmittedClaim($this->client, $this->makeRewardCoupon())->id);

        Bus::assertDispatched(SendEmailJob::class, fn (SendEmailJob $job) => $job->recipient_email === $selected->email);
        Bus::assertDispatched(SendEmailJob::class, fn (SendEmailJob $job) => $job->recipient_email === 'promos@example.com');
        Bus::assertNotDispatched(SendEmailJob::class, fn (SendEmailJob $job) => $job->recipient_email === $excluded->email);
        Bus::assertDispatched(SendEmailJob::class, 2);
    }

    public function test_the_email_carries_the_gift_card_details(): void
    {
        $this->makeUserWithRole('admin', ['first_name' => 'Abby']);
        $coupon = $this->makeRewardCoupon();
        $claim  = $this->makeSubmittedClaim($this->client, $coupon, [
            'recipient_email' => 'jane.gifts@example.com',
            'gift_card_brand' => 'target',
            'order_total'     => 1250.0,
            'reward_amount'   => 125.0,
        ]);

        $this->runJob($claim->id);

        Bus::assertDispatched(SendEmailJob::class, function (SendEmailJob $job) use ($claim, $coupon) {
            $mail = $job->mailable;

            return $mail->recipient_name === 'Abby'
                && $mail->client_name === 'Jane Client'
                && $mail->client_company === 'Acme'
                && $mail->client_initials === 'JC'
                && $mail->promo_code === 'HAPPYHOLIDAYS'
                && $mail->gift_card_brand === 'target'
                && $mail->gift_card_label === 'Target'
                && $mail->gift_card_email === 'jane.gifts@example.com'
                && $mail->reward_amount === '$125.00'
                && $mail->reward_percentage === '10%'
                && $mail->order_total === '$1,250.00'
                && $mail->order_reference === strtoupper(substr($claim->session_id, 0, 8))
                && $mail->products === 'Link Building'
                && $mail->is_payment_pending === false
                && str_contains($mail->view_order_url, '/admin/orders/' . $claim->order_ids[0])
                && str_contains($mail->view_claims_url, '/admin/coupons/' . $coupon->id . '/edit#gift-card-claims');
        });
    }

    public function test_multi_order_checkouts_link_to_the_session_page(): void
    {
        $this->makeUserWithRole('admin');
        $claim = $this->makeSubmittedClaim($this->client, $this->makeRewardCoupon(), [
            'order_ids'     => [(string) Str::uuid(), (string) Str::uuid()],
            'product_types' => ['link_building', 'new_content'],
        ]);

        $this->runJob($claim->id);

        Bus::assertDispatched(SendEmailJob::class, fn (SendEmailJob $job) => str_contains($job->mailable->view_order_url, '/admin/orders/session/' . $claim->session_id)
            && $job->mailable->products === 'Link Building, New Content');
    }

    public function test_nothing_is_sent_while_details_are_missing(): void
    {
        $this->makeUserWithRole('admin');
        $claim = $this->makeClaim($this->client, $this->makeRewardCoupon());

        $this->runJob($claim->id);
        $this->runJob((string) Str::uuid());

        Bus::assertNotDispatched(SendEmailJob::class);
    }

    // ─── Promotion dates ──────────────────────────────────────────────────────

    public function test_the_promo_end_date_is_shown_in_the_email(): void
    {
        $this->makeUserWithRole('admin');
        $coupon = $this->makeRewardCoupon(['expires_at' => '2026-12-31 23:59:59']);

        $this->travelTo('2026-12-01 10:00:00');
        $this->runJob($this->makeSubmittedClaim($this->client, $coupon)->id);

        Bus::assertDispatched(SendEmailJob::class, fn (SendEmailJob $job) => $job->mailable->promotion_ends_at === 'December 31, 2026');
    }

    public function test_nothing_is_sent_when_details_arrive_after_the_promo_ended(): void
    {
        $this->makeUserWithRole('admin');
        $coupon = $this->makeRewardCoupon(['expires_at' => '2026-12-31 23:59:59']);
        $claim  = $this->makeSubmittedClaim($this->client, $coupon, ['submitted_at' => '2027-01-01 08:00:00']);

        $this->runJob($claim->id);

        Bus::assertNotDispatched(SendEmailJob::class);
    }

    public function test_nothing_is_sent_when_details_arrive_before_the_promo_started(): void
    {
        $this->makeUserWithRole('admin');
        $coupon = $this->makeRewardCoupon(['starts_at' => '2026-12-01 00:00:00', 'expires_at' => '2026-12-31 23:59:59']);
        $claim  = $this->makeSubmittedClaim($this->client, $coupon, ['submitted_at' => '2026-11-30 23:00:00']);

        $this->runJob($claim->id);

        Bus::assertNotDispatched(SendEmailJob::class);
    }

    public function test_a_request_made_before_the_deadline_is_still_sent_when_the_queue_runs_late(): void
    {
        $this->makeUserWithRole('admin');
        $coupon = $this->makeRewardCoupon(['expires_at' => '2026-12-31 23:59:59']);
        $claim  = $this->makeSubmittedClaim($this->client, $coupon, ['submitted_at' => '2026-12-31 23:58:00']);

        $this->travelTo('2027-01-01 00:05:00');
        $this->runJob($claim->id);

        Bus::assertDispatched(SendEmailJob::class, 1);
    }

    public function test_promos_without_dates_always_notify(): void
    {
        $this->makeUserWithRole('admin');
        $coupon = $this->makeRewardCoupon(['starts_at' => null, 'expires_at' => null]);

        $this->runJob($this->makeSubmittedClaim($this->client, $coupon)->id);

        Bus::assertDispatched(SendEmailJob::class, fn (SendEmailJob $job) => $job->mailable->promotion_ends_at === '');
    }

    public function test_nothing_is_sent_for_a_claim_whose_coupon_is_not_a_money_back_promo(): void
    {
        $this->makeUserWithRole('admin');
        $claim = $this->makeSubmittedClaim($this->client, $this->makeRegularCoupon());

        $this->runJob($claim->id);

        Bus::assertNotDispatched(SendEmailJob::class);
    }
}
