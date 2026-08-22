<?php

namespace Tests\Feature\EmailNotification;

use App\Jobs\SendEmailInterceptCopyJob;
use App\Mail\InterceptedEmailCopy;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendEmailInterceptCopyJobTest extends TestCase
{
    public function test_handle_sends_the_copy_to_the_configured_destination(): void
    {
        Mail::fake();

        $job = new SendEmailInterceptCopyJob(
            original_subject: 'Order Status Update',
            original_recipient_email: 'client@example.com',
            html_body: '<p>Your order is now live.</p>',
            copy_recipient_email: 'auditor@agency.com',
        );

        $job->handle();

        Mail::assertSent(InterceptedEmailCopy::class, function (InterceptedEmailCopy $mail) {
            return $mail->hasTo('auditor@agency.com')
                && $mail->original_subject === 'Order Status Update'
                && $mail->original_recipient_email === 'client@example.com'
                && $mail->html_body === '<p>Your order is now live.</p>';
        });
    }

    public function test_handle_prefixes_the_original_subject_with_copy(): void
    {
        Mail::fake();

        (new SendEmailInterceptCopyJob(
            original_subject: 'Order Status Update',
            original_recipient_email: 'client@example.com',
            html_body: '<p>Body</p>',
            copy_recipient_email: 'auditor@agency.com',
        ))->handle();

        Mail::assertSent(InterceptedEmailCopy::class, function (InterceptedEmailCopy $mail) {
            return $mail->envelope()->subject === '[Copy] Order Status Update';
        });
    }

    public function test_dispatch_staggered_applies_no_delay_at_position_zero(): void
    {
        $frozen_now = now();
        $this->travelTo($frozen_now);

        Bus::fake([SendEmailInterceptCopyJob::class]);

        SendEmailInterceptCopyJob::dispatchStaggered(
            original_subject: 'Subject',
            original_recipient_email: 'client@example.com',
            html_body: '<p>Body</p>',
            copy_recipient_email: 'auditor@agency.com',
            position: 0,
        );

        Bus::assertDispatched(
            SendEmailInterceptCopyJob::class,
            fn (SendEmailInterceptCopyJob $job) => $job->delay->equalTo($frozen_now)
        );
    }

    public function test_dispatch_staggered_delays_by_min_stagger_seconds_per_position(): void
    {
        $frozen_now = now();
        $this->travelTo($frozen_now);

        Bus::fake([SendEmailInterceptCopyJob::class]);

        SendEmailInterceptCopyJob::dispatchStaggered(
            original_subject: 'Subject',
            original_recipient_email: 'client@example.com',
            html_body: '<p>Body</p>',
            copy_recipient_email: 'third@agency.com',
            position: 2,
        );

        $expected_delay = $frozen_now->copy()->addSeconds(2 * SendEmailInterceptCopyJob::MIN_STAGGER_SECONDS);

        Bus::assertDispatched(
            SendEmailInterceptCopyJob::class,
            fn (SendEmailInterceptCopyJob $job) => $job->delay->equalTo($expected_delay)
        );
    }
}
