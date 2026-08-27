<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Support\FrontendUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class GoogleAuthController extends Controller
{
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')->stateless()->redirect();
    }

    public function callback(): RedirectResponse
    {
        try {
            $google_user = Socialite::driver('google')->stateless()->user();
        } catch (\Throwable $e) {
            Log::error('Google OAuth callback error', ['message' => $e->getMessage()]);
            return redirect(FrontendUrl::to('/signin') . '?error=google_auth_failed');
        }

        $user = User::where('google_id', $google_user->getId())
            ->orWhere('email', $google_user->getEmail())
            ->first();

        if ($user) {
            if (! $user->google_id) {
                $user->update(['google_id' => $google_user->getId()]);
            }

            if (! $user->is_active) {
                return redirect(FrontendUrl::to('/signin') . '?error=account_disabled');
            }
        } else {
            $name_parts = explode(' ', trim((string) $google_user->getName()), 2);
            $first_name = $name_parts[0] ?? '';
            $last_name  = $name_parts[1] ?? '';

            $default_organization = Organization::findDefault();

            $user = User::create([
                'first_name'        => $first_name,
                'last_name'         => $last_name,
                'email'             => $google_user->getEmail(),
                'google_id'         => $google_user->getId(),
                'password'          => Str::random(32),
                'organization_id'   => $default_organization?->id,
                'email_verified_at' => now(),
            ]);

            $user->preference()->create();
            $user->billingAddress()->create();
            $user->assignRole('client');
        }

        $user->update(['last_login_at' => now()]);

        /** @var string $token */
        $token      = auth()->login($user);
        $expires_in = auth()->factory()->getTTL() * 60;

        return redirect(FrontendUrl::to('/auth/google/callback') . '?' . http_build_query([
            'token'      => $token,
            'expires_in' => $expires_in,
        ]));
    }
}
