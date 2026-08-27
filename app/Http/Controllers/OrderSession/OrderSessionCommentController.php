<?php

namespace App\Http\Controllers\OrderSession;

use App\Http\Controllers\Controller;
use App\Http\Requests\OrderSessionComment\StoreOrderSessionCommentRequest;
use App\Jobs\SendAdminCommentNotificationJob;
use App\Models\OrderSessionComment;
use App\Services\NotificationService;
use App\Services\OrderSessionCommentService;
use App\Support\FrontendUrl;
use Illuminate\Http\JsonResponse;

class OrderSessionCommentController extends Controller
{
    public function __construct(
        protected OrderSessionCommentService $comment_service,
        protected NotificationService $notification_service,
    ) {}

    public function index(string $session_id): JsonResponse
    {
        $user          = auth()->user();
        $owner_user_id = $this->comment_service->findSessionOwnerUserId($session_id);

        if ($owner_user_id !== null && $user->id !== $owner_user_id && !$this->comment_service->isAdminOrStaff($user)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

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
        $user          = auth()->user();
        $owner_user_id = $this->comment_service->findSessionOwnerUserId($session_id);

        if ($owner_user_id !== null && $user->id !== $owner_user_id && !$this->comment_service->isAdminOrStaff($user)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

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

        $is_admin = $this->comment_service->isAdminOrStaff($user);

        $comment = OrderSessionComment::create([
            'session_id'       => $session_id,
            'user_id'          => $user->id,
            'parent_id'        => $parent_id,
            'content'          => $validated['content'],
            'is_admin_comment' => $is_admin,
        ]);

        $comment->load(['user']);

        $this->comment_service->notifyOnNewComment($comment, $user, $this->notification_service);

        if (!$is_admin) {
            $session_details = $this->comment_service->findSessionDetails($session_id);
            $order_id        = (string) ($session_details->id ?? '');
            $order_title     = $session_details->order_title ?? '';
            $client_name     = $user->first_name . ' ' . $user->last_name;
            $client_initials = strtoupper(substr($user->first_name, 0, 1) . substr($user->last_name, 0, 1));
            $comment_date    = $comment->created_at->format('F j, Y \a\t g:i A');

            dispatch(new SendAdminCommentNotificationJob(
                order_id:         $order_id,
                order_title:      $order_title,
                client_name:      $client_name,
                client_email:     $user->email,
                client_initials:  $client_initials,
                comment_content:  $comment->content,
                comment_date:     $comment_date,
                view_comment_url: $this->comment_service->buildCommentUrl(
                    true,
                    null,
                    $session_id,
                    $comment->id
                ),
                settings_url:     FrontendUrl::to('/admin/email-notifications'),
            ))->onQueue('emails');
        }

        return response()->json(
            ['data' => $this->comment_service->formatComment($comment)],
            201
        );
    }
}
