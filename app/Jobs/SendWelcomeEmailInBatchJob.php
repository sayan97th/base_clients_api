<?php

namespace App\Jobs;

use App\Mail\ClientPlatformWelcomeEmail;
use App\Models\BulkEmailBatch;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;

class SendWelcomeEmailInBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [15, 30, 60];

    public function __construct(
        public int $user_id,
        public int $batch_id,
    ) {
        $this->onQueue('emails');
    }

    public function handle(): void
    {
        $batch = BulkEmailBatch::find($this->batch_id);

        if (! $batch || $batch->is_stopped || $batch->status !== 'processing') {
            return;
        }

        $user = User::find($this->user_id);

        if (! $user) {
            DB::table('bulk_email_batches')
                ->where('id', $this->batch_id)
                ->increment('failed_count');

            $this->checkCompletion();
            return;
        }

        $send_mode   = $batch->send_mode ?? 'not_sent';
        $should_skip = $send_mode === 'all_pending'
            ? $user->password_reset_at !== null
            : $user->welcome_email_sent_at !== null;

        if ($should_skip) {
            DB::table('bulk_email_batches')
                ->where('id', $this->batch_id)
                ->increment('skipped_count');

            $this->checkCompletion();
            return;
        }

        try {
            $token     = Password::createToken($user);
            $email     = urlencode($user->email);
            $reset_url = rtrim(config('app.frontend_url'), '/') . "/reset-password/{$token}?email={$email}";

            Mail::to($user->email)->send(new ClientPlatformWelcomeEmail(
                user: $user,
                reset_url: $reset_url,
            ));

            $user->update(['welcome_email_sent_at' => now()]);

            DB::table('bulk_email_batches')
                ->where('id', $this->batch_id)
                ->increment('sent_count');
        } catch (\Throwable $e) {
            DB::table('bulk_email_batches')
                ->where('id', $this->batch_id)
                ->increment('failed_count');
        }

        $this->checkCompletion();
    }

    public function failed(\Throwable $exception): void
    {
        DB::table('bulk_email_batches')
            ->where('id', $this->batch_id)
            ->increment('failed_count');

        $this->checkCompletion();
    }

    private function checkCompletion(): void
    {
        $batch = BulkEmailBatch::find($this->batch_id);

        if (! $batch || $batch->status !== 'processing') {
            return;
        }

        if ($batch->isComplete()) {
            $batch->markCompleted();
        }
    }
}
