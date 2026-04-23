<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreNewContentOrderRequest;
use App\Http\Resources\NewContentOrderResource;
use App\Models\NewContentTier;
use App\Models\NewContentOrderBilling;
use App\Models\NewContentOrder;
use App\Models\NewContentOrderItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stripe\StripeClient;

class NewContentOrderController extends Controller
{
    /**
     * Create a new instance of the controller.
     */
    public function __construct()
    {
        $this->middleware('auth:api');
    }

    /**
     * Create a new content order with Stripe payment.
     *
     * POST /api/new-content/orders
     *
     * @param StoreNewContentOrderRequest $request
     * @return JsonResponse
     */
    public function store(StoreNewContentOrderRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = Auth::user();

        // Calculate total from items
        $calculatedTotal = 0;
        foreach ($validated['items'] as $item) {
            $calculatedTotal += $item['quantity'] * $item['unit_price'];
        }

        // Validate total amount matches
        if ((int) $calculatedTotal !== (int) $validated['total_amount']) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => [
                    'total_amount' => ['Total amount does not match the sum of items'],
                ],
                'status_code' => Response::HTTP_UNPROCESSABLE_ENTITY,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            // Initialize Stripe client
            $stripe = new StripeClient(config('services.stripe.secret'));

            // Process payment with Stripe
            $paymentIntent = $stripe->paymentIntents->create([
                'amount' => (int) ($validated['total_amount'] * 100), // Convert to cents
                'currency' => 'usd',
                'payment_method' => $validated['payment']['payment_method_id'],
                'confirm' => true,
                'automatic_payment_methods' => [
                    'enabled' => true,
                    'allow_redirects' => 'never',
                ],
            ]);

            // Check if payment was successful
            if ($paymentIntent->status !== 'succeeded') {
                return response()->json([
                    'message' => 'Payment processing failed',
                    'error' => 'Payment was not completed. Status: ' . $paymentIntent->status,
                    'status_code' => Response::HTTP_BAD_REQUEST,
                ], Response::HTTP_BAD_REQUEST);
            }

            // Start database transaction
            return DB::transaction(function () use ($user, $validated, $paymentIntent) {
                // Generate unique order ID
                $orderId = 'order_' . Str::random(12);

                // Create order
                $order = NewContentOrder::create([
                    'order_id' => $orderId,
                    'user_id' => $user->id,
                    'order_title' => $validated['order_title'] ?? null,
                    'order_notes' => $validated['order_notes'] ?? null,
                    'total_amount' => $validated['total_amount'],
                    'status' => 'processing',
                    'stripe_payment_intent_id' => $paymentIntent->id,
                ]);

                // Create order items
                foreach ($validated['items'] as $item) {
                    $orderItemId = 'order_item_' . Str::random(12);
                    $subtotal = $item['quantity'] * $item['unit_price'];

                    NewContentOrderItem::create([
                        'order_item_id' => $orderItemId,
                        'new_content_order_id' => $order->id,
                        'article_tier_id' => $item['article_tier_id'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'subtotal' => $subtotal,
                    ]);
                }

                // Create billing information
                $billingId = 'billing_' . Str::random(12);
                NewContentOrderBilling::create([
                    'billing_id' => $billingId,
                    'new_content_order_id' => $order->id,
                    'company' => $validated['billing']['company'] ?? null,
                    'address' => $validated['billing']['address'],
                    'city' => $validated['billing']['city'],
                    'state' => $validated['billing']['state'],
                    'country' => $validated['billing']['country'],
                    'postal_code' => $validated['billing']['postal_code'],
                ]);

                return response()->json([
                    'data' => [
                        'id' => $orderId,
                        'status' => 'processing',
                        'total_amount' => (int) $validated['total_amount'],
                    ],
                ], Response::HTTP_CREATED);
            });
        } catch (\Stripe\Exception\ApiErrorException $e) {
            // Handle Stripe API errors
            return response()->json([
                'message' => 'Payment processing failed',
                'error' => $e->getMessage(),
                'status_code' => Response::HTTP_BAD_REQUEST,
            ], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            // Handle other exceptions
            return response()->json([
                'message' => 'An error occurred while processing your order',
                'error' => $e->getMessage(),
                'status_code' => Response::HTTP_INTERNAL_SERVER_ERROR,
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get order details.
     *
     * GET /api/new-content/orders/{id}
     *
     * @param string $id
     * @return JsonResponse
     */
    public function show(string $id): JsonResponse
    {
        $user = Auth::user();

        $order = NewContentOrder::where('order_id', $id)
            ->with(['items.newContentTier', 'billing'])
            ->first();

        if (!$order) {
            return response()->json([
                'message' => 'Order not found',
                'status_code' => Response::HTTP_NOT_FOUND,
            ], Response::HTTP_NOT_FOUND);
        }

        // Verify user owns the order
        if ($order->user_id !== $user->id) {
            return response()->json([
                'message' => 'Order not found',
                'status_code' => Response::HTTP_NOT_FOUND,
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'data' => new NewContentOrderResource($order),
        ], Response::HTTP_OK);
    }
}
