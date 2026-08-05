<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon authorization checks.
     *
     * The default package behavior bypasses the gate whenever the app runs
     * under the "local" environment, which is exactly the case this project
     * needed to lock down, so the auth closure is redefined here without
     * that bypass. Access is granted only to authenticated admin side users
     * (super_admin, admin or staff), resolved from the "web" guard since the
     * Horizon dashboard is protected with DB backed HTTP basic auth rather
     * than the JWT guard used by the rest of the API.
     */
    protected function authorization(): void
    {
        $this->gate();

        Horizon::auth(function ($request) {
            return Gate::forUser($request->user('web'))->check('viewHorizon');
        });
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null) {
            return $user !== null
                && $user->is_active
                && $user->hasRole(['super_admin', 'admin', 'staff']);
        });
    }
}
