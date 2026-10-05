<?php

namespace App\Http\Controllers\Invoice;

use App\Http\Controllers\Controller;
use App\Http\Requests\Invoice\CreateInvoicePaymentIntentRequest;
use App\Models\Invoice;
use App\Models\PaymentProfile;
use App\Models\User;
use App\Services\InvoiceCardPaymentService;
use Illuminate\Http\JsonResponse;

class InvoicePaymentIntentController extends Controller
{
    public function __construct(
        protected InvoiceCardPaymentService $card_payment_service,
    ) {}

    /**
     * POST /api/invoices/{unique_id}/payment-intent
     *
     * Creates a card-only, manual-capture PaymentIntent for the invoice pay
     * page. The amount always comes from the invoice record. Like the unified
     * /pay endpoint, the Authorization header selects the flow:
     *   - authenticated: invoice owner, may pay with a saved card or save a new one
     *   - public:        share-link token, new card only
     */
    public function store(CreateInvoicePaymentIntentRequest $request, string $unique_id): JsonResponse
    {
        if ($request->hasHeader('Authorization')) {
            return $this->storeAuthenticated($request, $unique_id);
        }

        return $this->storePublic($request, $unique_id);
    }

    private function storeAuthenticated(CreateInvoicePaymentIntentRequest $request, string $unique_id): JsonResponse
    {
        try {
            /** @var User|null $user */
            $user = auth('api')->user();
        } catch (\Exception) {
            $user = null;
        }

        if (! $user || ! $user->is_active) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $invoice = Invoice::where('unique_id', $unique_id)
            ->where('user_id', $user->id)
            ->first();

        if (! $invoice) {
            $exists = Invoice::where('unique_id', $unique_id)->exists();

            return $exists
                ? response()->json(['message' => 'This invoice does not belong to your account.'], 403)
                : response()->json(['message' => 'Invoice not found.'], 404);
        }

        $payment_profile    = null;
        $payment_profile_id = $request->input('payment_profile_id');

        if ($payment_profile_id) {
            $payment_profile = PaymentProfile::where('id', $payment_profile_id)
                ->where('user_id', $user->id)
                ->first();

            if (! $payment_profile) {
                return response()->json(['message' => 'Saved card not found.'], 404);
            }

            if ($this->card_payment_service->isPaymentProfileExpired($payment_profile)) {
                return response()->json(['message' => 'This card has expired. Please choose another card.'], 422);
            }
        }

        $result = $this->card_payment_service->createPaymentIntent(
            invoice: $invoice,
            user: $user,
            payment_profile: $payment_profile,
            save_card: $request->boolean('save_card'),
        );

        return $this->respond($result);
    }

    private function storePublic(CreateInvoicePaymentIntentRequest $request, string $unique_id): JsonResponse
    {
        $token = (string) $request->input('token', '');

        if ($token === '') {
            return response()->json(['message' => 'Token is required.'], 401);
        }

        $invoice = Invoice::where('unique_id', $unique_id)->first();

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

        if (! $invoice->sharing_enabled || ! hash_equals((string) $invoice->share_key, $token)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        // Saved cards are never exposed or chargeable through a share link.
        $result = $this->card_payment_service->createPaymentIntent(invoice: $invoice, user: null);

        return $this->respond($result);
    }

    private function respond(array $result): JsonResponse
    {
        if (! $result['success']) {
            return response()->json(['message' => $result['message']], $result['status_code'] ?? 422);
        }

        return response()->json([
            'data' => [
                'client_secret'     => $result['client_secret'],
                'payment_intent_id' => $result['payment_intent_id'],
            ],
        ]);
    }
}
