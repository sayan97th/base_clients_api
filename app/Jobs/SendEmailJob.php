<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $max_exceptions = 2;

    public array $backoff = [30, 60, 120];

    public function __construct(
        public Mailable $mailable,
        public string $recipient_email,
    ) {
        $this->onQueue('emails');
    }

    public function middleware(): array
    {
        return [
            new RateLimited('emails'),
        ];
    }

    public function handle(): void
    {
        Mail::to($this->recipient_email)->send($this->mailable);
    }

    public function failed(\Throwable $exception): void
    {
        \Log::error('Email delivery failed', [
            'recipient' => $this->recipient_email,
            'mailable' => get_class($this->mailable),
            'error' => $exception->getMessage(),
        ]);
    }

    public static function dispatchWithThrottle(
        Mailable $mailable,
        string $recipient_email,
        int $position = 0,
    ): void {
        $delay_seconds = $position * (int) config('queue.email_throttle_delay', 5);

        static::dispatch($mailable, $recipient_email)
            ->delay(now()->addSeconds($delay_seconds));
    }
}
