<?php

namespace App\Http\Controllers\Client\Autopay;

use App\Http\Controllers\Controller;
use App\Models\AutopaySetting;
use App\Models\InvoiceChargeAttempt;
use App\Models\PaymentProfile;
use App\Models\User;
use App\Services\SavedCardChargeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Client-facing autopay management: opt in (with recorded consent), change the
 * card or the per-invoice limit, and opt out at any time.
 */
class AutopayController extends Controller
{
    public function __construct(protected SavedCardChargeService $charge_service) {}

    /**
     * GET /api/autopay
     */
    public function show(): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $setting = AutopaySetting::with('paymentProfile')->where('user_id', $user->id)->first();

        return response()->json(['data' => $this->formatSetting($setting, $user)]);
    }

    /**
     * PUT /api/autopay
     *
     * Enables autopay, or updates the card / limit while it is enabled.
     */
    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'payment_profile_id' => ['required', 'string', 'uuid'],
            'max_amount'         => ['nullable', 'numeric', 'min:1', 'max:1000000'],
            'consent_accepted'   => ['required', 'accepted'],
        ]);

        /** @var User $user */
        $user = auth()->user();

        $payment_profile = PaymentProfile::where('id', $request->input('payment_profile_id'))
            ->where('user_id', $user->id)
            ->first();

        if (! $payment_profile) {
            return response()->json(['message' => 'Saved card not found.'], 404);
        }

        if ($this->isExpired($payment_profile)) {
            return response()->json(['message' => 'This card has expired. Please choose another card.'], 422);
        }

        $setting     = AutopaySetting::firstOrNew(['user_id' => $user->id]);
        $was_enabled = (bool) $setting->is_enabled;

        $setting->fill([
            'payment_profile_id' => $payment_profile->id,
            'is_enabled'         => true,
            'max_amount'         => $request->input('max_amount'),
            'consent_text'       => AutopaySetting::CONSENT_TEXT,
            'consent_ip'         => $request->ip(),
            'consent_user_agent' => mb_substr((string) $request->userAgent(), 0, 512),
            'disabled_at'        => null,
            'disabled_by_type'   => null,
            'disabled_by_name'   => null,
            'disabled_reason'    => null,
        ]);

        // A fresh opt-in only covers invoices created from now on. Changing the
        // card or limit while enabled keeps the original start date.
        if (! $was_enabled) {
            $setting->enabled_at = now();
        }

        $setting->save();

        logger()->info('Autopay ' . ($was_enabled ? 'updated' : 'enabled') . " by client {$user->id}", [
            'payment_profile_id' => $payment_profile->id,
            'max_amount'         => $setting->max_amount,
            'ip'                 => $request->ip(),
        ]);

        return response()->json([
            'data'    => $this->formatSetting($setting->fresh('paymentProfile'), $user),
            'message' => $was_enabled ? 'Autopay settings updated.' : 'Autopay enabled.',
        ]);
    }

    /**
     * DELETE /api/autopay
     */
    public function destroy(): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $setting = AutopaySetting::where('user_id', $user->id)->first();

        if ($setting && $setting->is_enabled) {
            $setting->disable('client', $user->full_name ?: $user->email, 'Turned off by the client.');

            logger()->info("Autopay disabled by client {$user->id}");
        }

        return response()->json([
            'data'    => $this->formatSetting($setting?->fresh('paymentProfile'), $user),
            'message' => 'Autopay turned off. Future invoices will not be charged automatically.',
        ]);
    }

    private function formatSetting(?AutopaySetting $setting, User $user): array
    {
        $profile = $setting?->paymentProfile;

        $recent_charges = InvoiceChargeAttempt::with('invoice:id,unique_id,invoice_number')
            ->where('user_id', $user->id)
            ->where('source', InvoiceChargeAttempt::SOURCE_AUTOPAY)
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn (InvoiceChargeAttempt $attempt) => [
                'id'                => $attempt->id,
                'invoice_number'    => $attempt->invoice?->invoice_number,
                'invoice_unique_id' => $attempt->invoice?->unique_id,
                'amount'            => $attempt->amount,
                'status'            => $attempt->status,
                'card_label'        => $this->charge_service->buildCardLabel($attempt->card_brand, $attempt->card_last_four),
                'failure_message'   => $attempt->failure_message,
                'next_retry_at'     => $attempt->next_retry_at?->toIso8601String(),
                'created_at'        => $attempt->created_at?->toIso8601String(),
            ])
            ->values();

        return [
            'is_enabled'         => (bool) $setting?->is_enabled,
            'payment_profile_id' => $setting?->payment_profile_id,
            'payment_profile'    => $profile ? [
                'id'           => $profile->id,
                'card_brand'   => $profile->card_brand,
                'last_four'    => $profile->last_four,
                'expiry_month' => $profile->expiry_month,
                'expiry_year'  => $profile->expiry_year,
            ] : null,
            'max_amount'         => $setting?->max_amount,
            'enabled_at'         => $setting?->enabled_at?->toIso8601String(),
            'disabled_at'        => $setting?->disabled_at?->toIso8601String(),
            'disabled_by_type'   => $setting?->disabled_by_type,
            'disabled_reason'    => $setting?->disabled_reason,
            'consent_text'       => AutopaySetting::CONSENT_TEXT,
            'max_attempts'       => SavedCardChargeService::MAX_AUTOPAY_ATTEMPTS,
            'recent_charges'     => $recent_charges,
        ];
    }

    private function isExpired(PaymentProfile $profile): bool
    {
        $year  = (int) $profile->expiry_year;
        $month = (int) $profile->expiry_month;

        if ($year <= 0 || $month <= 0) {
            return false;
        }

        if ($year < 100) {
            $year += 2000;
        }

        return now()->startOfMonth()->gt(now()->setDate($year, $month, 1)->startOfMonth());
    }
}
