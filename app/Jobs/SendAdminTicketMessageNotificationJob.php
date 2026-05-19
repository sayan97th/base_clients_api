<?php

namespace App\Jobs;

use App\Mail\AdminTicketMessageNotification;
use App\Services\EmailNotificationSettingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendAdminTicketMessageNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $max_exceptions = 2;

    public array $backoff = [30, 60, 120];

    public function __construct(
        public string $ticket_number,
        public string $ticket_subject,
        public string $client_name,
        public string $client_email,
        public string $client_initials,
        public string $message_content,
        public string $message_date,
        public string $view_ticket_url,
        public string $settings_url,
    ) {
        $this->onQueue('emails');
    }

    public function handle(): void
    {
        $recipients = EmailNotificationSettingService::resolveAdminRecipients();

        foreach ($recipients as $position => $recipient) {
            SendEmailJob::dispatchWithThrottle(
                new AdminTicketMessageNotification(
                    recipient_name:   $recipient['name'],
                    recipient_email:  $recipient['email'],
                    ticket_number:    $this->ticket_number,
                    ticket_subject:   $this->ticket_subject,
                    client_name:      $this->client_name,
                    client_email:     $this->client_email,
                    client_initials:  $this->client_initials,
                    message_content:  $this->message_content,
                    message_date:     $this->message_date,
                    view_ticket_url:  $this->view_ticket_url,
                    settings_url:     $this->settings_url,
                ),
                $recipient['email'],
                $position,
            );
        }
    }

    public function failed(\Throwable $exception): void
    {
        \Log::error('SendAdminTicketMessageNotificationJob failed', [
            'ticket_number' => $this->ticket_number,
            'error'         => $exception->getMessage(),
        ]);
    }
}
