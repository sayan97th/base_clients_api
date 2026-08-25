<?php

namespace App\Console\Commands\Email;

use App\Mail\ServiceVerificationEmail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class VerifyEmailService extends Command
{
    # php artisan email:verify-service --to=you@example.com --mailer=mailgun
    protected $signature = 'email:verify-service
                            {--to= : Recipient email address (required)}
                            {--name= : Recipient display name (defaults to email address)}
                            {--mailer= : Mailer to use: mailgun, mailtrap, mailpit, smtp (defaults to MAIL_MAILER)}';

    protected $description = 'Send a verification email to confirm the email delivery service is operational';

    public function handle(): int
    {
        $this->info('=== Email Service Verification ===');
        $this->newLine();

        $to = $this->resolveRecipientEmail();
        if ($to === null) {
            return self::FAILURE;
        }

        $name   = $this->option('name') ?: $to;
        $mailer = $this->resolveMailer();

        $this->displayConfiguration($mailer, $to);

        if (!$this->confirm('Send verification email now?', true)) {
            $this->line('Aborted.');
            return self::SUCCESS;
        }

        return $this->sendVerification($to, $name, $mailer);
    }

    private function resolveRecipientEmail(): ?string
    {
        $to = $this->option('to');

        if (!$to) {
            $to = $this->ask('Recipient email address');
        }

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->error("Invalid email address: [{$to}]");
            return null;
        }

        return $to;
    }

    private function resolveMailer(): string
    {
        $requested = $this->option('mailer');

        $supported = ['mailgun', 'mailtrap', 'mailpit', 'smtp', 'log'];

        if ($requested) {
            if (!in_array($requested, $supported, true)) {
                $this->warn("Unknown mailer [{$requested}]. Falling back to default.");
                return config('mail.default');
            }
            return $requested;
        }

        return config('mail.default');
    }

    private function displayConfiguration(string $mailer, string $to): void
    {
        $this->line('<fg=cyan>Configuration:</>');

        $rows = [
            ['Default Mailer', config('mail.default')],
            ['Using Mailer',   $mailer],
            ['From Address',   config('mail.from.address')],
            ['From Name',      config('mail.from.name')],
            ['Recipient',      $to],
        ];

        if ($mailer === 'mailgun') {
            $rows[] = ['Mailgun Domain', config('services.mailgun.domain', '(not set)')];
            $rows[] = ['Mailgun Endpoint', config('services.mailgun.endpoint', 'api.mailgun.net')];
        }

        if (in_array($mailer, ['mailtrap', 'mailpit', 'smtp'], true)) {
            $config_key = in_array($mailer, ['mailtrap', 'mailpit'], true) ? "mailers.{$mailer}" : 'mailers.smtp';
            $host = config("mail.{$config_key}.host", '(not set)');
            $port = config("mail.{$config_key}.port", '(not set)');
            $rows[] = ['SMTP Host', $host];
            $rows[] = ['SMTP Port', (string) $port];
        }

        $this->table(['Setting', 'Value'], $rows);
        $this->newLine();
    }

    private function sendVerification(string $to, string $name, string $mailer): int
    {
        $verification_token = strtoupper(Str::random(8)) . '-' . strtoupper(Str::random(8));
        $sent_at            = now()->format('Y-m-d H:i:s T');

        $service_info = $this->buildServiceInfo($mailer);

        $this->line("Sending verification email via <fg=yellow>{$mailer}</> to <fg=yellow>{$to}</>...");

        $start = microtime(true);

        try {
            Mail::mailer($mailer)->to($to, $name)->send(
                new ServiceVerificationEmail(
                    recipient_name:     $name,
                    recipient_email:    $to,
                    verification_token: $verification_token,
                    mailer_name:        $mailer,
                    sent_at:            $sent_at,
                    service_info:       $service_info,
                )
            );
        } catch (Throwable $e) {
            $elapsed = round((microtime(true) - $start) * 1000);
            $this->newLine();
            $this->error("Email delivery FAILED after {$elapsed}ms.");
            $this->newLine();
            $this->line('<fg=red>Error:</> ' . $e->getMessage());
            $this->newLine();
            $this->line('<fg=yellow>Troubleshooting hints:</>');
            $this->line('  • Check your .env MAIL_MAILER and MAILGUN_* / MAILTRAP_* / MAILPIT_* credentials.');
            $this->line('  • Run <fg=cyan>php artisan config:clear</> if you recently changed .env values.');
            $this->line('  • For Mailgun, verify the domain is active in your Mailgun dashboard.');
            $this->line('  • For Mailpit, confirm the Mailpit container/service is running and listening on the configured SMTP port.');
            $this->line('  • For SMTP, confirm the host and port are reachable from this server.');
            return self::FAILURE;
        }

        $elapsed = round((microtime(true) - $start) * 1000);

        $this->newLine();
        $this->info("Verification email sent successfully in {$elapsed}ms.");
        $this->newLine();

        $this->table(
            ['Field', 'Value'],
            [
                ['Recipient',           $to],
                ['Mailer',              $mailer],
                ['Verification Token',  $verification_token],
                ['Sent At',             $sent_at],
                ['Delivery Time',       "{$elapsed}ms"],
            ]
        );

        $this->newLine();
        $this->line('<fg=green>✓ Email service is operational.</>');

        return self::SUCCESS;
    }

    private function buildServiceInfo(string $mailer): array
    {
        $info = [
            'Application'    => config('app.name'),
            'Environment'    => config('app.env'),
            'Mailer'         => $mailer,
            'From Address'   => config('mail.from.address'),
        ];

        if ($mailer === 'mailgun') {
            $info['Mailgun Domain']   = config('services.mailgun.domain', '(not set)');
            $info['Mailgun Endpoint'] = config('services.mailgun.endpoint', 'api.mailgun.net');
        }

        if (in_array($mailer, ['mailtrap', 'mailpit', 'smtp'], true)) {
            $config_key = in_array($mailer, ['mailtrap', 'mailpit'], true) ? "mailers.{$mailer}" : 'mailers.smtp';
            $info['SMTP Host'] = config("mail.{$config_key}.host", '(not set)');
            $info['SMTP Port'] = (string) config("mail.{$config_key}.port", '(not set)');
        }

        $info['Server Time'] = now()->format('Y-m-d H:i:s T');
        $info['PHP Version'] = PHP_VERSION;

        return $info;
    }
}
