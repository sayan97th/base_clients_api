<?php

namespace App\Http\Controllers\Admin\Autopay;

use App\Http\Controllers\Controller;
use App\Models\AutopaySetting;
use App\Models\InvoiceChargeAttempt;
use App\Models\User;
use App\Services\AutopayService;
use App\Services\SavedCardChargeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * Admin visibility over automatic / card-on-file charges: every attempt with
 * its outcome, the clients enrolled in autopay, and a health check that shows
 * whether the scheduler is actually running.
 */
class AdminAutopayController extends Controller
{
    /** The command runs hourly; flag it as unhealthy after two missed runs. */
    private const HEALTHY_RUN_WINDOW_MINUTES = 150;

    public function __construct(protected SavedCardChargeService $charge_service) {}

    /**
     * GET /api/admin/autopay/attempts
     */
    public function attempts(Request $request): JsonResponse
    {
        $request->validate([
            'status'    => ['nullable', Rule::in(InvoiceChargeAttempt::STATUSES)],
            'source'    => ['nullable', Rule::in(InvoiceChargeAttempt::SOURCES)],
            'search'    => ['nullable', 'string', 'max:255'],
            'date_from' => ['nullable', 'date'],
            'date_to'   => ['nullable', 'date'],
            'page'      => ['nullable', 'integer', 'min:1'],
            'per_page'  => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $search = trim((string) $request->input('search', ''));

        $paginator = InvoiceChargeAttempt::with(['invoice:id,unique_id,invoice_number,status', 'user:id,first_name,last_name,email'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('source'), fn ($q) => $q->where('source', $request->input('source')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->input('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('created_at', '<=', $request->input('date_to')))
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($inner) use ($search) {
                    $inner->where('stripe_payment_intent_id', 'like', "%{$search}%")
                        ->orWhereHas('invoice', fn ($iq) => $iq->where('invoice_number', 'like', "%{$search}%")
                            ->orWhere('unique_id', 'like', "%{$search}%"))
                        ->orWhereHas('user', fn ($uq) => $uq->where('email', 'like', "%{$search}%")
                            ->orWhere('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%"));
                });
            })
            ->latest()
            ->paginate((int) $request->input('per_page', 20));

        return response()->json([
            'data'         => collect($paginator->items())->map(fn ($attempt) => $this->formatAttempt($attempt))->values(),
            'current_page' => $paginator->currentPage(),
            'last_page'    => $paginator->lastPage(),
            'per_page'     => $paginator->perPage(),
            'total'        => $paginator->total(),
        ]);
    }

    /**
     * GET /api/admin/autopay/enrollments
     */
    public function enrollments(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::in(['enabled', 'disabled'])],
        ]);

        $settings = AutopaySetting::with(['user:id,first_name,last_name,email', 'paymentProfile'])
            ->when($request->input('status') === 'enabled', fn ($q) => $q->where('is_enabled', true))
            ->when($request->input('status') === 'disabled', fn ($q) => $q->where('is_enabled', false))
            ->orderByDesc('is_enabled')
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (AutopaySetting $setting) => [
                'id'               => $setting->id,
                'user'             => $setting->user ? [
                    'id'         => $setting->user->id,
                    'first_name' => $setting->user->first_name,
                    'last_name'  => $setting->user->last_name,
                    'email'      => $setting->user->email,
                ] : null,
                'is_enabled'       => $setting->is_enabled,
                'card_label'       => $setting->paymentProfile
                    ? $this->charge_service->buildCardLabel($setting->paymentProfile->card_brand, $setting->paymentProfile->last_four)
                    : null,
                'max_amount'       => $setting->max_amount,
                'enabled_at'       => $setting->enabled_at?->toIso8601String(),
                'disabled_at'      => $setting->disabled_at?->toIso8601String(),
                'disabled_by_type' => $setting->disabled_by_type,
                'disabled_by_name' => $setting->disabled_by_name,
                'disabled_reason'  => $setting->disabled_reason,
                'consent_ip'       => $setting->consent_ip,
            ])
            ->values();

        return response()->json(['data' => $settings]);
    }

    /**
     * GET /api/admin/autopay/health
     */
    public function health(): JsonResponse
    {
        $last_run   = Cache::get(AutopayService::LAST_RUN_CACHE_KEY);
        $last_ran_at = isset($last_run['ran_at']) ? \Carbon\Carbon::parse($last_run['ran_at']) : null;

        return response()->json([
            'last_run_at'         => $last_ran_at?->toIso8601String(),
            'last_run_summary'    => $last_run['summary'] ?? null,
            'scheduler_healthy'   => $last_ran_at !== null && $last_ran_at->gt(now()->subMinutes(self::HEALTHY_RUN_WINDOW_MINUTES)),
            'enrolled_clients'    => AutopaySetting::where('is_enabled', true)->count(),
            'processing_count'    => InvoiceChargeAttempt::where('status', InvoiceChargeAttempt::STATUS_PROCESSING)->count(),
            'requires_review'     => InvoiceChargeAttempt::where('status', InvoiceChargeAttempt::STATUS_REQUIRES_REVIEW)->count(),
            'failed_last_30_days' => InvoiceChargeAttempt::where('status', InvoiceChargeAttempt::STATUS_FAILED)
                ->where('created_at', '>=', now()->subDays(30))->count(),
            'succeeded_last_30_days' => InvoiceChargeAttempt::where('status', InvoiceChargeAttempt::STATUS_SUCCEEDED)
                ->where('created_at', '>=', now()->subDays(30))->count(),
            'collected_last_30_days' => (float) InvoiceChargeAttempt::where('status', InvoiceChargeAttempt::STATUS_SUCCEEDED)
                ->where('created_at', '>=', now()->subDays(30))->sum('amount'),
        ]);
    }

    /**
     * DELETE /api/admin/autopay/enrollments/{user_id}
     */
    public function disable(string $user_id): JsonResponse
    {
        $setting = AutopaySetting::where('user_id', $user_id)->first();

        if (! $setting) {
            return response()->json(['message' => 'This client is not enrolled in autopay.'], 404);
        }

        /** @var User $admin */
        $admin = Auth::user();

        if ($setting->is_enabled) {
            $setting->disable('admin', $admin->full_name ?: $admin->email, 'Turned off by an administrator.');

            logger()->info("Autopay disabled by admin {$admin->id} for client {$user_id}");
        }

        return response()->json(['message' => 'Autopay turned off for this client.']);
    }

    private function formatAttempt(InvoiceChargeAttempt $attempt): array
    {
        return [
            'id'                       => $attempt->id,
            'invoice'                  => $attempt->invoice ? [
                'id'             => $attempt->invoice->id,
                'unique_id'      => $attempt->invoice->unique_id,
                'invoice_number' => $attempt->invoice->invoice_number,
                'status'         => $attempt->invoice->status,
            ] : null,
            'user'                     => $attempt->user ? [
                'id'         => $attempt->user->id,
                'first_name' => $attempt->user->first_name,
                'last_name'  => $attempt->user->last_name,
                'email'      => $attempt->user->email,
            ] : null,
            'source'                   => $attempt->source,
            'attempt_number'           => $attempt->attempt_number,
            'status'                   => $attempt->status,
            'amount'                   => $attempt->amount,
            'card_label'               => $this->charge_service->buildCardLabel($attempt->card_brand, $attempt->card_last_four),
            'stripe_payment_intent_id' => $attempt->stripe_payment_intent_id,
            'failure_code'             => $attempt->failure_code,
            'failure_message'          => $attempt->failure_message,
            'next_retry_at'            => $attempt->next_retry_at?->toIso8601String(),
            'initiated_by_name'        => $attempt->initiated_by_name,
            'created_at'               => $attempt->created_at?->toIso8601String(),
            'completed_at'             => $attempt->completed_at?->toIso8601String(),
        ];
    }
}
