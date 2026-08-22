<?php

namespace App\Jobs;

use App\Mail\InterceptedEmailCopy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * Sends one independent copy of an already-sent email to a single Email
 * Interceptor destination. InterceptOutgoingEmailListener dispatches one of
 * these per configured destination, each staggered at least
 * MIN_STAGGER_SECONDS apart via dispatchStaggered() so a mailbox with
 * several interceptor addresses configured never looks like a burst of
 * near-simultaneous requests to the mail provider.
 */
class SendEmailInterceptCopyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const MIN_STAGGER_SECONDS = 1;

    public int $tries = 3;

    public int $max_exceptions = 2;

    public array $backoff = [30, 60, 120];

    public function __construct(
        public string $original_subject,
        public string $original_recipient_email,
        public string $html_body,
        public string $copy_recipient_email,
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
        Mail::to($this->copy_recipient_email)->send(new InterceptedEmailCopy(
            original_subject: $this->original_subject,
            original_recipient_email: $this->original_recipient_email,
            html_body: $this->html_body,
        ));
    }

    public function failed(\Throwable $exception): void
    {
        \Log::error('SendEmailInterceptCopyJob failed', [
            'copy_recipient_email' => $this->copy_recipient_email,
            'original_recipient'   => $this->original_recipient_email,
            'error'                => $exception->getMessage(),
        ]);
    }

    /**
     * Dispatches the copy delayed by $position * MIN_STAGGER_SECONDS, so that
     * dispatching one of these per interceptor destination (position 0, 1, 2...)
     * guarantees every copy leaves at least MIN_STAGGER_SECONDS after the last.
     */
    public static function dispatchStaggered(
        string $original_subject,
        string $original_recipient_email,
        string $html_body,
        string $copy_recipient_email,
        int $position = 0,
    ): void {
        $delay_seconds = $position * self::MIN_STAGGER_SECONDS;

        static::dispatch($original_subject, $original_recipient_email, $html_body, $copy_recipient_email)
            ->delay(now()->addSeconds($delay_seconds));
    }
}
