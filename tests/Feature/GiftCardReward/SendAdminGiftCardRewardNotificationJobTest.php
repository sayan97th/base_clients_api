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
}
