<?php

namespace App\Providers;

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
    }

    protected function configureRateLimiting(): void
    {
        RateLimiter::for('emails', function (object $job) {
            return Limit::perMinute(
                (int) config('queue.email_rate_limit', 10)
            );
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
