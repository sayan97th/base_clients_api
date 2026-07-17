<?php

namespace App\Http\Controllers\Client\Order;

use App\Http\Controllers\Controller;
use App\Models\ContentBriefOrder;
use App\Models\ContentOptimizationOrder;
use App\Models\LinkBuildingOrder;
use App\Models\NewContentOrder;
use App\Models\User;
use App\Services\OrderDetailsService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Client-facing endpoints for filling in the intake details of an order that
 * was purchased with those details deferred (status `pending_details`). Once
 * submitted, the order transitions out of `pending_details` and — for Link
 * Building — its turnaround clock starts.
 */
class OrderDetailsController extends Controller
{
    /** Statuses whose details a client is still allowed to edit. */
    private const EDITABLE_STATUSES = ['pending_details', 'payment_pending', 'new_request'];

    public function __construct(
        protected OrderDetailsService $orderDetailsService,
    ) {}

    public function linkBuilding(Request $request, string $order_id): JsonResponse
    {
        $order = $this->resolveOrder(LinkBuildingOrder::class, $order_id, ['items.placements']);
        if ($order instanceof JsonResponse) {
            return $order;
        }

        $data = $request->validate([
            'placements'                => ['required', 'array', 'min:1'],
            'placements.*.id'           => ['required', 'string'],
            'placements.*.keyword'      => ['nullable', 'string', 'max:500'],
            'placements.*.landing_page' => ['nullable', 'string', 'max:2048'],
            'placements.*.exact_match'  => ['nullable', 'boolean'],
        ]);

        $this->orderDetailsService->submitLinkBuildingDetails($order, $data['placements']);

        return $this->detailsResponse($order->fresh());
    }

    public function newContent(Request $request, string $order_id): JsonResponse
    {
        $order = $this->resolveOrder(NewContentOrder::class, $order_id, ['items.intakeRows']);
        if ($order instanceof JsonResponse) {
            return $order;
        }

        $data = $request->validate([
            'items'                                    => ['required', 'array', 'min:1'],
            'items.*.item_id'                          => ['required', 'string'],
            'items.*.intake_rows'                      => ['nullable', 'array'],
            'items.*.intake_rows.*.keyword_phrase'     => ['nullable', 'string', 'max:500'],
            'items.*.intake_rows.*.secondary_keywords' => ['nullable', 'string', 'max:500'],
            'items.*.intake_rows.*.type_of_content'    => ['nullable', 'string', 'in:Blog Article,Product Page,Home Page,About Us Page,Other'],
            'items.*.intake_rows.*.notes'              => ['nullable', 'string', 'max:5000'],
        ]);

        $this->orderDetailsService->submitNewContentDetails($order, $data['items']);

        return $this->detailsResponse($order->fresh());
    }

    public function contentOptimization(Request $request, string $order_id): JsonResponse
    {
        $order = $this->resolveOrder(ContentOptimizationOrder::class, $order_id, ['items.intakeRows']);
        if ($order instanceof JsonResponse) {
            return $order;
        }

        $data = $this->validateKeywordUrlItems($request);

        $this->orderDetailsService->submitContentOptimizationDetails($order, $data['items']);

        return $this->detailsResponse($order->fresh());
    }

    public function contentBrief(Request $request, string $order_id): JsonResponse
    {
        $order = $this->resolveOrder(ContentBriefOrder::class, $order_id, ['items.intakeRows']);
        if ($order instanceof JsonResponse) {
            return $order;
        }

        $data = $this->validateKeywordUrlItems($request);

        $this->orderDetailsService->submitContentBriefDetails($order, $data['items']);

        return $this->detailsResponse($order->fresh());
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @param  class-string<Model>  $model
     * @return Model|JsonResponse  the owned, editable order or an error response
     */
    private function resolveOrder(string $model, string $order_id, array $with): Model|JsonResponse
    {
        if (! Str::isUuid($order_id)) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        /** @var User $user */
        $user = auth()->user();

        $order = $model::where('id', $order_id)->with($with)->first();

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        if ($order->user_id !== $user->id) {
            return response()->json(['message' => 'This action is unauthorized.'], 403);
        }

        if (! in_array($order->status, self::EDITABLE_STATUSES, true)) {
            return response()->json([
                'message' => 'This order can no longer be edited because it is already being worked on.',
            ], 422);
        }

        return $order;
    }

    private function validateKeywordUrlItems(Request $request): array
    {
        return $request->validate([
            'items'                                     => ['required', 'array', 'min:1'],
            'items.*.item_id'                           => ['required', 'string'],
            'items.*.intake_rows'                       => ['nullable', 'array'],
            'items.*.intake_rows.*.primary_keyword'     => ['nullable', 'string', 'max:500'],
            'items.*.intake_rows.*.secondary_keywords'  => ['nullable', 'string', 'max:500'],
            'items.*.intake_rows.*.content_page_url'    => ['nullable', 'string', 'max:2048'],
            'items.*.intake_rows.*.notes'               => ['nullable', 'string', 'max:10000'],
        ]);
    }

    private function detailsResponse(Model $order): JsonResponse
    {
        return response()->json([
            'data' => [
                'id'         => $order->id,
                'status'     => $order->status,
                'is_pending' => $order->status === 'pending_details',
            ],
        ]);
    }
}
