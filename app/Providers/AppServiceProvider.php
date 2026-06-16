<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Horizon;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureRateLimiting();
        $this->configureHorizon();
        $this->configurePasswordReset();
    }

    protected function configurePasswordReset(): void
    {
        ResetPassword::createUrlUsing(function ($user, string $token) {
            $frontend_url = rtrim(config('app.frontend_url'), '/');
            $email        = urlencode($user->getEmailForPasswordReset());

            return "{$frontend_url}/reset-password/{$token}?email={$email}";
        });
    }

    protected function configureRateLimiting(): void
    {
        RateLimiter::for('emails', function (object $job) {
            $throttle_delay = max(1, (int) config('queue.email_throttle_delay', 3));
            $per_minute     = (int) floor(60 / $throttle_delay);

            return Limit::perMinute($per_minute);
        });
    }

    protected function configureHorizon(): void
    {
        Horizon::auth(function ($request) {
            if (app()->environment('local')) {
                return true;
            }

            $user = $request->user();

            return $user && $user->hasRole('super_admin');
        });
    }
}
