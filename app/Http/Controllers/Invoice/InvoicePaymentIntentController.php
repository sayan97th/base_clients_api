<?php

namespace App\Http\Controllers\Invoice;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\PaymentProfile;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/invoices/{unique_id}/payment-intent
 *
 * Creates the Stripe PaymentIntent used by the invoice pay page. The amount
 * always comes from the invoice on the server — never from the browser — and
 * the intent is restricted to credit/debit cards.
 *
 * Like InvoicePayController, the presence of an Authorization header selects
 * the flow:
 *  - Authenticated: the client may pay with one of their saved cards
 *    (payment_profile_id) or a new card, optionally saving it (save_card).
 *  - Public share link: authorized by the share token; new card only — saved
 *    cards are never exposed or chargeable through an anonymous link.
 */
class InvoicePaymentIntentController extends Controller
{
    private const PAYABLE_STATUSES = ['unpaid', 'overdue'];

    public function __construct(protected StripeService $stripe_service) {}

    public function store(Request $request, string $unique_id): JsonResponse
    {
        if ($request->hasHeader('Authorization')) {
            return $this->storeAuthenticated($request, $unique_id);
        }

        return $this->storePublic($request, $unique_id);
    }

    private function storeAuthenticated(Request $request, string $unique_id): JsonResponse
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

        $request->validate([
            'payment_profile_id' => ['nullable', 'string', 'uuid'],
            'save_card'          => ['nullable', 'boolean'],
        ]);

        $invoice = Invoice::where('unique_id', $unique_id)->first();

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

        if ((string) $invoice->user_id !== (string) $user->id) {
            return response()->json(['message' => 'This invoice does not belong to your account.'], 403);
        }

        if ($error = $this->validatePayable($invoice)) {
            return $error;
        }

        $payment_profile = null;

        if ($request->filled('payment_profile_id')) {
            $payment_profile = PaymentProfile::where('id', $request->input('payment_profile_id'))
                ->where('user_id', $user->id)
                ->first();

            if (! $payment_profile) {
                return response()->json(['message' => 'Saved card not found.'], 404);
            }
        }

        $customer_result = $this->stripe_service->findOrCreateCustomer($user);

        if (! $customer_result['success']) {
            return response()->json(['message' => 'Failed to initialize payment. Please try again.'], 502);
        }

        $result = $this->stripe_service->createCardPaymentIntent(
            amount_cents: $this->amountCents($invoice),
            stripe_customer_id: $customer_result['customer_id'],
            stripe_payment_method_id: $payment_profile?->stripe_payment_method_id,
            save_for_future: $payment_profile === null && $request->boolean('save_card'),
            metadata: $this->buildMetadata($invoice, 'invoice_pay_page'),
            description: "Invoice {$invoice->invoice_number}",
        );

        return $this->respond($result, $invoice);
    }

    private function storePublic(Request $request, string $unique_id): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
        ]);

        $invoice = Invoice::where('unique_id', $unique_id)->first();

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

        if (! $invoice->sharing_enabled || ! hash_equals((string) $invoice->share_key, (string) $request->input('token'))) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        if ($error = $this->validatePayable($invoice)) {
            return $error;
        }

        $result = $this->stripe_service->createCardPaymentIntent(
            amount_cents: $this->amountCents($invoice),
            stripe_customer_id: null,
            stripe_payment_method_id: null,
            save_for_future: false,
            metadata: $this->buildMetadata($invoice, 'invoice_share_link'),
            description: "Invoice {$invoice->invoice_number}",
        );

        return $this->respond($result, $invoice);
    }

    private function validatePayable(Invoice $invoice): ?JsonResponse
    {
        if (! in_array($invoice->status, self::PAYABLE_STATUSES, true)) {
            return response()->json(['message' => 'This invoice cannot be paid in its current status.'], 400);
        }

        if ($invoice->currency_type !== 'usd') {
            return response()->json(['message' => 'This invoice cannot be paid with a card.'], 400);
        }

        if ($this->amountCents($invoice) < 50) {
            return response()->json(['message' => 'The invoice total is below the minimum card payment.'], 400);
        }

        return null;
    }

    private function amountCents(Invoice $invoice): int
    {
        return (int) round((float) $invoice->total_amount * 100);
    }

    private function buildMetadata(Invoice $invoice, string $source): array
    {
        return [
            'invoice_unique_id' => $invoice->unique_id,
            'invoice_id'        => (string) $invoice->id,
            'user_id'           => (string) $invoice->user_id,
            'payment_source'    => $source,
        ];
    }

    private function respond(array $result, Invoice $invoice): JsonResponse
    {
        if (! $result['success']) {
            logger()->error("Failed to create invoice PaymentIntent for {$invoice->unique_id}", [
                'error' => $result['message'] ?? null,
            ]);

            return response()->json(['message' => 'Failed to initialize payment. Please try again.'], 502);
        }

        return response()->json([
            'client_secret'     => $result['client_secret'],
            'payment_intent_id' => $result['payment_intent_id'],
            'amount_cents'      => $this->amountCents($invoice),
        ]);
    }
}
