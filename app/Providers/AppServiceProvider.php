<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Gate;
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
