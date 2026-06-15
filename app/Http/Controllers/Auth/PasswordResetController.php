<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

class PasswordResetController extends Controller
{
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink(
            $request->only('email')
        );

        // Always return success to prevent user enumeration attacks.
        return response()->json([
            'message' => 'We have emailed your password reset link.',
        ]);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, string $password) {
                $user->forceFill([
                    'password'           => Hash::make($password),
                    'password_reset_at'  => now(),
                ])->save();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'Your password has been reset successfully.',
            ]);
        }

        return response()->json([
            'message' => __($status),
            'errors'  => [
                'email' => [__($status)],
            ],
        ], 422);
    }
}
