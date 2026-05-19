<?php

namespace App\Jobs;

use App\Mail\TicketReplyClientNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendClientTicketReplyNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $max_exceptions = 2;

    public array $backoff = [30, 60, 120];

    public function __construct(
        public string $ticket_number,
        public string $ticket_subject,
        public string $ticket_id,
        public string $client_name,
        public string $client_email,
        public string $admin_name,
        public string $admin_initials,
        public string $reply_content,
        public string $reply_date,
        public string $view_ticket_url,
    ) {
        $this->onQueue('emails');
    }

    public function handle(): void
    {
        SendEmailJob::dispatchWithThrottle(
            new TicketReplyClientNotification(
                client_name:      $this->client_name,
                client_email:     $this->client_email,
                ticket_number:    $this->ticket_number,
                ticket_subject:   $this->ticket_subject,
                admin_name:       $this->admin_name,
                admin_initials:   $this->admin_initials,
                reply_content:    $this->reply_content,
                reply_date:       $this->reply_date,
                view_ticket_url:  $this->view_ticket_url,
            ),
            $this->client_email,
        );
    }

    public function failed(\Throwable $exception): void
    {
        \Log::error('SendClientTicketReplyNotificationJob failed', [
            'ticket_number' => $this->ticket_number,
            'client_email'  => $this->client_email,
            'error'         => $exception->getMessage(),
        ]);
    }
}
