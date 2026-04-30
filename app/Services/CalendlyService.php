<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CalendlyService
{
    private string $base_url = 'https://api.calendly.com';
    private ?string $token;

    public function __construct()
    {
        $this->token = config('services.calendly.token');
    }

    public function resolveStartTime(string $event_uri): ?Carbon
    {
        if (! $this->token) {
            return null;
        }

        try {
            $response = Http::withToken($this->token)
                ->timeout(10)
                ->get($event_uri);

            if ($response->successful()) {
                $start_time = $response->json('resource.start_time');

                if ($start_time) {
                    return Carbon::parse($start_time);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('CalendlyService: failed to resolve start time', [
                'event_uri' => $event_uri,
                'error'     => $e->getMessage(),
            ]);
        }

        return null;
    }

    public function cancelEvent(string $event_uri, ?string $reason = null): bool
    {
        if (! $this->token) {
            return false;
        }

        try {
            $payload = [];

            if ($reason) {
                $payload['reason'] = $reason;
            }

            $response = Http::withToken($this->token)
                ->timeout(10)
                ->post("{$event_uri}/cancellation", $payload);

            return $response->successful() || $response->status() === 409;
        } catch (\Throwable $e) {
            Log::warning('CalendlyService: failed to cancel event', [
                'event_uri' => $event_uri,
                'error'     => $e->getMessage(),
            ]);

            return false;
        }
    }
}
