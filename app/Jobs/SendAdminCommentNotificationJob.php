<?php

namespace App\Jobs;

use App\Mail\AdminOrderCommentNotification;
use App\Services\EmailNotificationSettingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendAdminCommentNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $max_exceptions = 2;

    public array $backoff = [30, 60, 120];

    public function __construct(
        public string $order_id,
        public string $order_title,
        public string $client_name,
        public string $client_email,
        public string $client_initials,
        public string $comment_content,
        public string $comment_date,
        public string $view_comment_url,
        public string $settings_url,
    ) {
        $this->onQueue('emails');
    }

    public function handle(): void
    {
        $recipients = EmailNotificationSettingService::resolveAdminRecipients();

        foreach ($recipients as $position => $recipient) {
            SendEmailJob::dispatchWithThrottle(
                new AdminOrderCommentNotification(
                    recipient_name:   $recipient['name'],
                    recipient_email:  $recipient['email'],
                    client_name:      $this->client_name,
                    client_email:     $this->client_email,
                    client_initials:  $this->client_initials,
                    order_id:         $this->order_id,
                    order_title:      $this->order_title,
                    comment_content:  $this->comment_content,
                    comment_date:     $this->comment_date,
                    view_comment_url: $this->view_comment_url,
                    settings_url:     $this->settings_url,
                ),
                $recipient['email'],
                $position,
            );
        }
    }

    public function failed(\Throwable $exception): void
    {
        \Log::error('SendAdminCommentNotificationJob failed', [
            'order_id' => $this->order_id,
            'error'    => $exception->getMessage(),
        ]);
    }
}
