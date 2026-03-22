<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Writer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorController extends Controller
{
    public function __construct(private Google2FA $google2fa) {}

    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'is_enabled' => (bool) $user->two_factor_enabled,
            'enabled_at' => $user->two_factor_enabled_at?->toIso8601String(),
        ]);
    }

    public function setup(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->two_factor_enabled) {
            return response()->json([
                'message' => 'Two-factor authentication is already enabled for this account.',
            ], 422);
        }

        $secret = $this->google2fa->generateSecretKey();

        $user->update(['two_factor_secret' => $secret]);

        $qrCodeUrl = $this->google2fa->getQRCodeUrl(
            config('app.name'),
            $user->email,
            $secret,
        );

        return response()->json([
            'qr_code_url' => $this->generateQrCodeImage($qrCodeUrl),
            'secret'      => $secret,
        ]);
    }

    public function verify(Request $request): JsonResponse
    {
        $request->validate(['code' => 'required|string']);

        $user   = $request->user();
        $secret = $user->two_factor_secret;

        if (! $secret || ! $this->google2fa->verifyKey($secret, $request->code)) {
            return response()->json([
                'message' => 'The verification code is invalid or has expired. Please try again.',
                'errors'  => [
                    'code' => ['The verification code is invalid or has expired. Please try again.'],
                ],
            ], 422);
        }

        $plain_recovery_codes = $this->generateRecoveryCodes();

        $user->update([
            'two_factor_enabled'        => true,
            'two_factor_enabled_at'     => now(),
            'two_factor_recovery_codes' => json_encode(
                array_map(fn ($code) => Hash::make($code), $plain_recovery_codes)
            ),
        ]);

        return response()->json([
            'message'        => 'Two-factor authentication has been enabled successfully.',
            'recovery_codes' => $plain_recovery_codes,
        ]);
    }

    public function disable(Request $request): JsonResponse
    {
        $request->validate(['code' => 'required|string']);

        $user = $request->user();

        if (! $user->two_factor_enabled) {
            return response()->json([
                'message' => 'Two-factor authentication is not enabled for this account.',
            ], 422);
        }

        if (! $this->google2fa->verifyKey($user->two_factor_secret, $request->code)) {
            return response()->json([
                'message' => 'The verification code is invalid. Please try again.',
                'errors'  => [
                    'code' => ['The verification code is invalid. Please try again.'],
                ],
            ], 422);
        }

        $user->update([
            'two_factor_secret'         => null,
            'two_factor_enabled'        => false,
            'two_factor_enabled_at'     => null,
            'two_factor_recovery_codes' => null,
        ]);

        return response()->json([
            'message' => 'Two-factor authentication has been disabled successfully.',
        ]);
    }

    private function generateQrCodeImage(string $qr_code_url): string
    {
        $renderer = new GDLibRenderer(200);
        $writer   = new Writer($renderer);
        $png      = $writer->writeString($qr_code_url);

        return 'data:image/png;base64,' . base64_encode($png);
    }

    private function generateRecoveryCodes(): array
    {
        return array_map(
            fn () => Str::lower(Str::random(4)) . '-' . Str::lower(Str::random(4)),
            range(1, 8)
        );
    }
}
