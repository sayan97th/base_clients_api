<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Http\JsonResponse;
use Stripe\Exception\ApiErrorException;

class BillingController extends Controller
{
    public function __construct(
        protected StripeService $stripeService
    ) {}

    /**
     * GET /billing/setup-intent
     *
     * Returns a Stripe SetupIntent client_secret.
     * The frontend passes this to stripe.confirmCardSetup() to securely
     * tokenize a card. Once confirmed, the frontend sends the resulting
     * pm_xxx back to POST /billing/payment-methods.
     */
    public function createSetupIntent(): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        try {
            $result = $this->stripeService->createSetupIntent($user);

            return response()->json(['data' => $result]);
        } catch (ApiErrorException $e) {
            return response()->json([
                'message' => 'Unable to initialize payment setup: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * GET /billing/overview
     *
     * Returns a summary of the user's billing profile:
     * - saved payment methods
     * - recent invoices
     * - default payment method
     */
    public function overview(): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $payment_methods = $user->paymentMethods()
            ->orderBy('is_default', 'desc')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn ($pm) => $this->formatPaymentMethod($pm));

        $recent_invoices = Invoice::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(fn ($invoice) => [
                'unique_id'       => $invoice->unique_id,
                'invoice_number'  => $invoice->invoice_number,
                'status'          => $invoice->status,
                'payment_method'  => $invoice->payment_method,
                'total_amount'    => $invoice->total_amount,
                'date_issued'     => $invoice->date_issued?->format('F j, Y'),
                'date_paid'       => $invoice->date_paid?->format('F j, Y'),
            ]);

        $default_pm = $payment_methods->first(fn ($pm) => $pm['is_default']);

        return response()->json([
            'data' => [
                'has_payment_profile'    => $payment_methods->isNotEmpty(),
                'default_payment_method' => $default_pm,
                'payment_methods'        => $payment_methods,
                'recent_invoices'        => $recent_invoices,
                'stripe_customer_id'     => $user->stripe_customer_id,
            ],
        ]);
    }

    private function formatPaymentMethod(\App\Models\PaymentMethod $pm): array
    {
        return [
            'id'               => $pm->id,
            'card_brand'       => $pm->card_brand,
            'card_last_four'   => $pm->card_last_four,
            'card_exp_month'   => $pm->card_exp_month,
            'card_exp_year'    => $pm->card_exp_year,
            'cardholder_name'  => $pm->cardholder_name,
            'billing_zip'      => $pm->billing_zip,
            'is_default'       => $pm->is_default,
            'is_expired'       => $pm->isExpired(),
            'card_summary'     => $pm->card_summary,
            'expiry'           => $pm->expiry,
            'created_at'       => $pm->created_at,
        ];
    }
}
