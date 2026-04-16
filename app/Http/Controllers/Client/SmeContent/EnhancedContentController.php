<?php

namespace App\Http\Controllers\Client\SmeContent;

use App\Http\Controllers\Controller;
use App\Http\Requests\SmeContent\StoreEnhancedOrderRequest;
use App\Http\Requests\SmeContent\StoreEnhancedPaymentIntentRequest;
use App\Http\Requests\SmeContent\UpdateEnhancedOrderRequest;
use App\Models\SmeEnhancedOrder;
use App\Services\StripeService;
use Illuminate\Http\JsonResponse;

class EnhancedContentController extends Controller
{
    private const TIERS = [
        'sme_enhanced_1000' => [
            'id'          => 'sme_enhanced_1000',
            'label'       => 'SME Enhanced Content - 1,000-1,499 Words',
            'description' => 'We write the content and have a qualified SME review it for technical accuracy and put their name on the article.',
            'price'       => 1500,
        ],
        'sme_enhanced_1500' => [
            'id'          => 'sme_enhanced_1500',
            'label'       => 'SME Enhanced Content - 1,500-1,999 Words',
            'description' => 'We write the content and have a qualified SME review it for technical accuracy and put their name on the article.',
            'price'       => 2500,
        ],
        'sme_enhanced_2000' => [
            'id'          => 'sme_enhanced_2000',
            'label'       => 'SME Enhanced Content - 2,000+ Words',
            'description' => 'We write the content and have a qualified SME review it for technical accuracy and put their name on the article.',
            'price'       => 3500,
        ],
    ];

    private const FEATURES = [
        'Technical accuracy verification by qualified SMEs',
        'Identification of knowledge gaps and credibility issues',
        'Industry-specific terminology refinement',
        'Up-to-date information and best practices verification',
    ];

    private const CONTENT_TYPES = [
        'Home Page',
        'About Us Page',
        'Blog Article',
        'Product page',
    ];

    public function __construct(protected StripeService $stripeService) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => [
                'tiers'         => array_values(self::TIERS),
                'features'      => self::FEATURES,
                'content_types' => self::CONTENT_TYPES,
            ],
        ]);
    }

    public function tiers(): JsonResponse
    {
        return response()->json(['data' => array_values(self::TIERS)]);
    }

    public function features(): JsonResponse
    {
        return response()->json(['data' => self::FEATURES]);
    }

    public function contentTypes(): JsonResponse
    {
        return response()->json(['data' => self::CONTENT_TYPES]);
    }

    public function createPaymentIntent(StoreEnhancedPaymentIntentRequest $request): JsonResponse
    {
        $amount_cents = $request->amount * 100;

        $result = $this->stripeService->createPaymentIntent(
            $amount_cents,
            null,
            null,
            ['service' => 'sme_enhanced_content', 'email' => $request->email]
        );

        if (!$result['success']) {
            return response()->json(['message' => $result['message']], 422);
        }

        return response()->json([
            'data' => [
                'client_secret'      => $result['client_secret'],
                'payment_intent_id'  => $result['payment_intent_id'],
            ],
        ], 201);
    }

    public function storeOrder(StoreEnhancedOrderRequest $request): JsonResponse
    {
        $invalid_tiers = array_diff(array_keys($request->selected_tiers), array_keys(self::TIERS));

        if (!empty($invalid_tiers)) {
            return response()->json([
                'message' => 'One or more selected tiers are invalid.',
                'errors'  => ['selected_tiers' => ['One or more selected tiers are invalid.']],
            ], 422);
        }

        $verification = $this->stripeService->verifyPaymentIntent($request->payment_intent_id);

        if (!$verification['verified']) {
            return response()->json([
                'message' => 'Payment verification failed. The payment was not completed successfully.',
                'errors'  => ['payment_intent_id' => ['The provided payment could not be verified.']],
            ], 422);
        }

        $total_amount = $this->calculateTotal($request->selected_tiers);

        $order = SmeEnhancedOrder::create([
            'user_id'           => auth()->id(),
            'selected_tiers'    => $request->selected_tiers,
            'billing_address'   => $request->billing_address,
            'email'             => $request->email,
            'total_amount'      => $total_amount,
            'status'            => 'paid',
            'payment_intent_id' => $request->payment_intent_id,
        ]);

        return response()->json(['data' => $this->formatOrder($order)], 201);
    }

    public function indexOrders(): JsonResponse
    {
        $orders = SmeEnhancedOrder::where('user_id', auth()->id())
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'data' => $orders->map(fn ($o) => $this->formatOrder($o)),
        ]);
    }

    public function showOrder(int $id): JsonResponse
    {
        $order = SmeEnhancedOrder::find($id);

        if (!$order || $order->user_id !== auth()->id()) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        return response()->json(['data' => $this->formatOrder($order)]);
    }

    public function updateOrder(UpdateEnhancedOrderRequest $request, int $id): JsonResponse
    {
        $order = SmeEnhancedOrder::find($id);

        if (!$order || $order->user_id !== auth()->id()) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $order->update(['status' => $request->status]);

        return response()->json(['data' => $this->formatOrder($order->fresh())]);
    }

    private function calculateTotal(array $selected_tiers): int
    {
        $total = 0;

        foreach ($selected_tiers as $tier_id => $quantity) {
            $tier   = self::TIERS[$tier_id] ?? null;
            $total += $tier ? $tier['price'] * $quantity : 0;
        }

        return $total;
    }

    private function formatOrder(SmeEnhancedOrder $order): array
    {
        return [
            'id'                 => $order->id,
            'selected_tiers'     => $order->selected_tiers,
            'billing_address'    => $order->billing_address,
            'email'              => $order->email,
            'total_amount'       => $order->total_amount,
            'status'             => $order->status,
            'payment_intent_id'  => $order->payment_intent_id,
            'created_at'         => $order->created_at,
            'updated_at'         => $order->updated_at,
        ];
    }
}
