<?php

namespace App\Jobs;

use App\Mail\AdminNewTicketNotification;
use App\Services\EmailNotificationSettingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendAdminNewTicketNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $max_exceptions = 2;

    public array $backoff = [30, 60, 120];

    public function __construct(
        public string $ticket_number,
        public string $ticket_subject,
        public string $ticket_priority,
        public string $client_name,
        public string $client_email,
        public string $client_initials,
        public string $initial_message,
        public string $ticket_date,
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
                new AdminNewTicketNotification(
                    recipient_name:   $recipient['name'],
                    recipient_email:  $recipient['email'],
                    ticket_number:    $this->ticket_number,
                    ticket_subject:   $this->ticket_subject,
                    ticket_priority:  $this->ticket_priority,
                    client_name:      $this->client_name,
                    client_email:     $this->client_email,
                    client_initials:  $this->client_initials,
                    initial_message:  $this->initial_message,
                    ticket_date:      $this->ticket_date,
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
        \Log::error('SendAdminNewTicketNotificationJob failed', [
            'ticket_number' => $this->ticket_number,
            'error'         => $exception->getMessage(),
        ]);
    }
}
