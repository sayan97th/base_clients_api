<?php

namespace App\Http\Controllers\Client\Credits;

use App\Http\Controllers\Controller;
use App\Mail\CreditPurchaseConfirmationMail;
use App\Models\CreditPackage;
use App\Models\CreditPurchase;
use App\Models\CreditTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class CreditPurchaseController extends Controller
{
    public function __construct(private readonly StripeService $stripe) {}

    public function purchase(Request $request): JsonResponse
    {
        $request->validate([
            'package_id'        => ['required', 'string', 'exists:credit_packages,id'],
            'credits_amount'    => ['required', 'integer', 'min:1'],
            'amount_paid'       => ['required', 'numeric', 'min:0.01'],
            'payment_intent_id' => ['required', 'string', 'max:255'],
        ]);

        if (CreditPurchase::where('payment_intent_id', $request->payment_intent_id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'This payment has already been processed.',
            ], 409);
        }

        $verification = $this->stripe->verifyPaymentIntent(
            $request->payment_intent_id,
            (float) $request->amount_paid,
        );

        if (!$verification['verified']) {
            return response()->json([
                'message' => 'Payment verification failed.',
                'errors'  => [
                    'payment_intent_id' => ['The payment could not be verified with Stripe.'],
                ],
            ], 422);
        }

        $package = CreditPackage::where('id', $request->package_id)
            ->where('is_active', true)
            ->firstOrFail();

        /** @var User $user */
        $user     = auth()->user();
        $purchase = null;

        try {
            DB::transaction(function () use ($user, $request, $package, &$purchase) {
                User::where('id', $user->id)->lockForUpdate()->first();
                $user->refresh();

                $purchase = CreditPurchase::create([
                    'user_id'           => $user->id,
                    'package_id'        => $package->id,
                    'package_name'      => $package->name,
                    'credits_amount'    => $request->credits_amount,
                    'amount_paid'       => $request->amount_paid,
                    'payment_intent_id' => $request->payment_intent_id,
                    'status'            => 'completed',
                ]);

                $user->increment('credit_balance', $request->credits_amount);

                CreditTransaction::create([
                    'user_id'     => $user->id,
                    'amount'      => $request->credits_amount,
                    'type'        => 'credit',
                    'description' => "Credit purchase — {$package->name}",
                    'created_by'  => null,
                ]);
            });
        } catch (\Throwable $e) {
            // DB transaction failed — void the Stripe authorization so the customer is not charged
            $this->stripe->cancelPaymentIntent($request->payment_intent_id);

            return response()->json([
                'success' => false,
                'message' => 'Your purchase could not be completed. Your payment authorization has been voided — you will not be charged. Please try again or contact support.',
            ], 500);
        }

        // DB committed — now capture the authorized payment
        $capture_result = $this->stripe->capturePaymentIntent($request->payment_intent_id);

        if (! $capture_result['success']) {
            \Illuminate\Support\Facades\Log::critical('Stripe capture FAILED after credit purchase DB commit — credits added but payment not collected.', [
                'user_id'           => $user->id,
                'payment_intent_id' => $request->payment_intent_id,
                'capture_error'     => $capture_result['message'] ?? 'Unknown error',
            ]);
        }

        $user->refresh();
        $new_balance = (int) $user->credit_balance;

        Transaction::create([
            'user_id'           => $user->id,
            'type'              => 'purchase',
            'status'            => 'success',
            'amount'            => (float) $request->amount_paid,
            'payment_method'    => 'credit_card',
            'payment_intent_id' => $request->payment_intent_id,
            'session_title'     => "Credit Purchase — {$package->name}",
            'description'       => "Purchased {$request->credits_amount} credits ({$package->name})",
        ]);

        Mail::queue(new CreditPurchaseConfirmationMail(
            user: $user,
            package_name: $package->name,
            credits_amount: (int) $request->credits_amount,
            amount_paid: (float) $request->amount_paid,
            new_balance: $new_balance,
            purchase_date: now(),
        ));

        return response()->json([
            'success'     => true,
            'new_balance' => $new_balance,
            'purchase_id' => $purchase->id,
            'message'     => 'Purchase successful. ' . number_format((int) $request->credits_amount) . ' credits have been added to your account.',
        ]);
    }
}
