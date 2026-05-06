<?php

namespace App\Http\Controllers\Client\OrderComment;

use App\Http\Controllers\Controller;
use App\Http\Requests\OrderSessionComment\StoreOrderSessionCommentRequest;
use App\Models\OrderSessionComment;
use App\Services\NotificationService;
use App\Services\OrderSessionCommentService;
use Illuminate\Http\JsonResponse;

class ClientOrderBasedCommentController extends Controller
{
    public function __construct(
        protected OrderSessionCommentService $comment_service,
        protected NotificationService $notification_service,
    ) {}

    public function index(string $order_id): JsonResponse
    {
        $user  = auth()->user();
        $order = $this->comment_service->findOrderDetails($order_id);

        if (!$order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        if ($user->id !== (int) $order->user_id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if (!$order->session_id) {
            return response()->json(['message' => 'This order has no session associated.'], 422);
        }

        $comments = OrderSessionComment::where('session_id', $order->session_id)
            ->whereNull('parent_id')
            ->with(['user', 'replies.user'])
            ->orderBy('created_at', 'asc')
            ->get();

        $data = $comments->map(fn ($c) => $this->comment_service->formatComment($c, true))->values()->all();

        return response()->json(['data' => $data]);
    }

    public function store(StoreOrderSessionCommentRequest $request, string $order_id): JsonResponse
    {
        $user  = auth()->user();
        $order = $this->comment_service->findOrderDetails($order_id);

        if (!$order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        if ($user->id !== (int) $order->user_id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if (!$order->session_id) {
            return response()->json(['message' => 'This order has no session associated.'], 422);
        }

        $validated = $request->validated();
        $parent_id = $validated['parent_id'] ?? null;

        if ($parent_id !== null) {
            $parent = OrderSessionComment::find($parent_id);

            if (!$parent || $parent->session_id !== $order->session_id || $parent->parent_id !== null) {
                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors'  => ['parent_id' => ['The selected parent_id is invalid.']],
                ], 422);
            }
        }

        $comment = OrderSessionComment::create([
            'session_id'       => $order->session_id,
            'user_id'          => $user->id,
            'parent_id'        => $parent_id,
            'content'          => $validated['content'],
            'is_admin_comment' => false,
        ]);

        $comment->load(['user']);

        $this->comment_service->notifyOnNewComment($comment, $user, $this->notification_service);

        return response()->json(
            ['data' => $this->comment_service->formatComment($comment)],
            201
        );
    }
}
