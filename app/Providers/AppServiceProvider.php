<?php

namespace App\Providers;

use App\Support\FrontendUrl;
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

    // Note: InterceptOutgoingEmailListener is intentionally NOT registered
    // here. Laravel auto-discovers it from app/Listeners by its handle()
    // type-hint (Illuminate\Mail\Events\MessageSending), matching every other
    // listener in this codebase. Registering it again here as well made the
    // event fire the listener twice per email, which is what was sending
    // every Email Interceptor copy two to three times over.

    protected function configurePasswordReset(): void
    {
        ResetPassword::createUrlUsing(function ($user, string $token) {
            $email = urlencode($user->getEmailForPasswordReset());

            return FrontendUrl::to('/reset-password/' . $token) . '?email=' . $email;
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
