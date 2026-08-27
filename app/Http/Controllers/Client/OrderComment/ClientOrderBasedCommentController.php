<?php

namespace App\Http\Controllers\Client\OrderComment;

use App\Http\Controllers\Controller;
use App\Http\Requests\OrderSessionComment\StoreOrderSessionCommentRequest;
use App\Jobs\SendAdminCommentNotificationJob;
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

        $query = $order->session_id
            ? OrderSessionComment::where('session_id', $order->session_id)
            : OrderSessionComment::where('order_id', $order_id);

        $comments = $query
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

        $validated = $request->validated();
        $parent_id = $validated['parent_id'] ?? null;

        if ($parent_id !== null) {
            $parent     = OrderSessionComment::find($parent_id);
            $same_scope = $order->session_id
                ? ($parent?->session_id === $order->session_id)
                : ($parent?->order_id === $order_id);

            if (!$parent || !$same_scope || $parent->parent_id !== null) {
                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors'  => ['parent_id' => ['The selected parent_id is invalid.']],
                ], 422);
            }
        }

        $attributes = [
            'user_id'          => $user->id,
            'parent_id'        => $parent_id,
            'content'          => $validated['content'],
            'is_admin_comment' => false,
        ];

        if ($order->session_id) {
            $attributes['session_id'] = $order->session_id;
        } else {
            $attributes['session_id'] = null;
            $attributes['order_id']   = $order_id;
        }

        $comment = OrderSessionComment::create($attributes);
        $comment->load(['user']);

        $this->comment_service->notifyOnNewComment($comment, $user, $this->notification_service);

        $client_name     = $user->first_name . ' ' . $user->last_name;
        $client_initials = strtoupper(substr($user->first_name, 0, 1) . substr($user->last_name, 0, 1));
        $comment_date    = $comment->created_at->format('F j, Y \a\t g:i A');

        dispatch(new SendAdminCommentNotificationJob(
            order_id:         $order_id,
            order_title:      $order->order_title ?? '',
            client_name:      $client_name,
            client_email:     $user->email,
            client_initials:  $client_initials,
            comment_content:  $comment->content,
            comment_date:     $comment_date,
            view_comment_url: $this->comment_service->buildCommentUrl(
                config('app.admin_url'),
                true,
                $order->session_id ? null : $order_id,
                $order->session_id,
                $comment->id
            ),
            settings_url:     config('app.admin_url') . '/admin/email-notifications',
        ))->onQueue('emails');

        return response()->json(
            ['data' => $this->comment_service->formatComment($comment)],
            201
        );
    }
}
