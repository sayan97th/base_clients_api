<?php

namespace App\Http\Controllers\Client\SmeContent;

use App\Http\Controllers\Controller;
use App\Http\Requests\SmeContent\StoreCollaborationOrderRequest;
use App\Http\Requests\SmeContent\StoreCollaborationPaymentIntentRequest;
use App\Http\Requests\SmeContent\UpdateCollaborationOrderRequest;
use App\Models\SmeCollaborationOrder;
use App\Models\SmeCollaborationTier;
use App\Services\StripeService;
use Illuminate\Http\JsonResponse;

class InternalCollaborationController extends Controller
{
    private const FEATURES = [
        'Expert knowledge extraction through structured interviews',
        'Transformation of technical insights into engaging content',
        'Preservation of authentic voice and specialized knowledge',
        'Content optimized for both accuracy and audience engagement',
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
                'tiers'         => $this->getTiers(),
                'features'      => self::FEATURES,
                'content_types' => self::CONTENT_TYPES,
            ],
        ]);
    }

    public function tiers(): JsonResponse
    {
        return response()->json(['data' => $this->getTiers()]);
    }

    public function features(): JsonResponse
    {
        return response()->json(['data' => self::FEATURES]);
    }

    public function contentTypes(): JsonResponse
    {
        return response()->json(['data' => self::CONTENT_TYPES]);
    }

    public function createPaymentIntent(StoreCollaborationPaymentIntentRequest $request): JsonResponse
    {
        $amount_cents = $request->amount * 100;

        $result = $this->stripeService->createPaymentIntent(
            $amount_cents,
            null,
            null,
            ['service' => 'sme_internal_collaboration', 'email' => $request->email]
        );

        if (!$result['success']) {
            return response()->json(['message' => $result['message']], 422);
        }

        return response()->json([
            'data' => [
                'client_secret'     => $result['client_secret'],
                'payment_intent_id' => $result['payment_intent_id'],
            ],
        ], 201);
    }

    public function storeOrder(StoreCollaborationOrderRequest $request): JsonResponse
    {
        $tier_keys    = array_keys($request->selected_tiers);
        $tiers_map    = SmeCollaborationTier::whereIn('tier_key', $tier_keys)
            ->where('is_active', true)
            ->get()
            ->keyBy('tier_key');

        $invalid_tiers = array_diff($tier_keys, $tiers_map->keys()->all());

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

        $total_amount = $this->calculateTotal($request->selected_tiers, $tiers_map);

        $order = SmeCollaborationOrder::create([
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
        $orders = SmeCollaborationOrder::where('user_id', auth()->id())
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'data' => $orders->map(fn ($o) => $this->formatOrder($o)),
        ]);
    }

    public function showOrder(int $id): JsonResponse
    {
        $order = SmeCollaborationOrder::find($id);

        if (!$order || $order->user_id !== auth()->id()) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        return response()->json(['data' => $this->formatOrder($order)]);
    }

    public function updateOrder(UpdateCollaborationOrderRequest $request, int $id): JsonResponse
    {
        $order = SmeCollaborationOrder::find($id);

        if (!$order || $order->user_id !== auth()->id()) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $order->update(['status' => $request->status]);

        return response()->json(['data' => $this->formatOrder($order->fresh())]);
    }

    private function getTiers(): array
    {
        return SmeCollaborationTier::where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($tier) => [
                'id'          => $tier->tier_key,
                'label'       => $tier->label,
                'description' => $tier->description,
                'price'       => $tier->price,
            ])
            ->values()
            ->all();
    }

    private function calculateTotal(array $selected_tiers, \Illuminate\Support\Collection $tiers_map): int
    {
        $total = 0;

        foreach ($selected_tiers as $tier_key => $quantity) {
            $tier   = $tiers_map->get($tier_key);
            $total += $tier ? $tier->price * $quantity : 0;
        }

        return $total;
    }

    private function formatOrder(SmeCollaborationOrder $order): array
    {
        return [
            'id'                => $order->id,
            'selected_tiers'    => $order->selected_tiers,
            'billing_address'   => $order->billing_address,
            'email'             => $order->email,
            'total_amount'      => $order->total_amount,
            'status'            => $order->status,
            'payment_intent_id' => $order->payment_intent_id,
            'created_at'        => $order->created_at,
            'updated_at'        => $order->updated_at,
        ];
    }
}
