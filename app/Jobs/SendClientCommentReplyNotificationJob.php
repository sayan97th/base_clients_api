<?php

namespace App\Jobs;

use App\Mail\ClientCommentReplyNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendClientCommentReplyNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $max_exceptions = 2;

    public array $backoff = [30, 60, 120];

    public function __construct(
        public string $client_name,
        public string $client_email,
        public string $order_id,
        public string $order_title,
        public string $original_comment_content,
        public string $original_comment_date,
        public string $reply_content,
        public string $reply_date,
        public string $admin_name,
        public string $admin_initials,
        public string $view_reply_url,
    ) {
        $this->onQueue('emails');
    }

    public function handle(): void
    {
        SendEmailJob::dispatchWithThrottle(
            new ClientCommentReplyNotification(
                client_name:               $this->client_name,
                client_email:              $this->client_email,
                order_id:                  $this->order_id,
                order_title:               $this->order_title,
                original_comment_content:  $this->original_comment_content,
                original_comment_date:     $this->original_comment_date,
                reply_content:             $this->reply_content,
                reply_date:                $this->reply_date,
                admin_name:                $this->admin_name,
                admin_initials:            $this->admin_initials,
                view_reply_url:            $this->view_reply_url,
            ),
            $this->client_email,
        );
    }

    public function failed(\Throwable $exception): void
    {
        \Log::error('SendClientCommentReplyNotificationJob failed', [
            'order_id'     => $this->order_id,
            'client_email' => $this->client_email,
            'error'        => $exception->getMessage(),
        ]);
    }
}
