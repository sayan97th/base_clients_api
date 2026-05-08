<?php

namespace App\Http\Controllers\Admin\OrderComment;

use App\Http\Controllers\Controller;
use App\Http\Requests\OrderSessionComment\StoreOrderSessionCommentRequest;
use App\Jobs\SendClientCommentReplyNotificationJob;
use App\Models\OrderSessionComment;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\OrderSessionCommentService;
use Illuminate\Http\JsonResponse;

class AdminOrderSessionCommentController extends Controller
{
    public function __construct(
        protected OrderSessionCommentService $comment_service,
        protected NotificationService $notification_service,
    ) {}

    public function index(string $session_id): JsonResponse
    {
        $comments = OrderSessionComment::where('session_id', $session_id)
            ->whereNull('parent_id')
            ->with(['user', 'replies.user'])
            ->orderBy('created_at', 'asc')
            ->get();

        $data = $comments->map(fn ($c) => $this->comment_service->formatComment($c, true))->values()->all();

        return response()->json(['data' => $data]);
    }

    public function store(StoreOrderSessionCommentRequest $request, string $session_id): JsonResponse
    {
        $user      = auth()->user();
        $validated = $request->validated();
        $parent_id = $validated['parent_id'] ?? null;

        if ($parent_id !== null) {
            $parent = OrderSessionComment::find($parent_id);

            if (!$parent || $parent->session_id !== $session_id || $parent->parent_id !== null) {
                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors'  => ['parent_id' => ['The selected parent_id is invalid.']],
                ], 422);
            }
        }

        $comment = OrderSessionComment::create([
            'session_id'       => $session_id,
            'user_id'          => $user->id,
            'parent_id'        => $parent_id,
            'content'          => $validated['content'],
            'is_admin_comment' => true,
        ]);

        $comment->load(['user']);

        $this->comment_service->notifyOnNewComment($comment, $user, $this->notification_service);

        $session_details = $this->comment_service->findSessionDetails($session_id);

        if ($session_details) {
            $client = User::find((int) $session_details->user_id);

            if ($client) {
                $order_id = (string) $session_details->id;

                $original_comment_content = '';
                $original_comment_date    = '';

                if ($parent_id !== null) {
                    $parent_comment           = OrderSessionComment::find($parent_id);
                    $original_comment_content = $parent_comment?->content ?? '';
                    $original_comment_date    = $parent_comment?->created_at
                        ? $parent_comment->created_at->format('F j, Y \a\t g:i A')
                        : '';
                }

                $admin_name     = $user->first_name . ' ' . $user->last_name;
                $admin_initials = strtoupper(substr($user->first_name, 0, 1) . substr($user->last_name, 0, 1));
                $reply_date     = $comment->created_at->format('F j, Y \a\t g:i A');

                dispatch(new SendClientCommentReplyNotificationJob(
                    client_name:              $client->first_name,
                    client_email:             $client->email,
                    order_id:                 $order_id,
                    order_title:              $session_details->order_title ?? '',
                    original_comment_content: $original_comment_content,
                    original_comment_date:    $original_comment_date,
                    reply_content:            $comment->content,
                    reply_date:               $reply_date,
                    admin_name:               $admin_name,
                    admin_initials:           $admin_initials,
                    view_reply_url:           config('app.client_url') . '/orders/' . $order_id,
                ))->onQueue('emails');
            }
        }

        return response()->json(
            ['data' => $this->comment_service->formatComment($comment)],
            201
        );
    }
}
