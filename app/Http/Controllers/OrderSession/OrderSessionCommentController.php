<?php

namespace App\Http\Controllers\OrderSession;

use App\Http\Controllers\Controller;
use App\Http\Requests\OrderSessionComment\StoreOrderSessionCommentRequest;
use App\Models\OrderSessionComment;
use App\Services\NotificationService;
use App\Services\OrderSessionCommentService;
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

        if ($owner_user_id === null) {
            return response()->json(['message' => 'Order session not found.'], 404);
        }

        if ($user->id !== $owner_user_id && !$this->comment_service->isAdminOrStaff($user)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $comments = OrderSessionComment::where('session_id', $session_id)
            ->whereNull('parent_id')
            ->with([
                'user.roles',
                'replies.user.roles',
            ])
            ->orderBy('created_at', 'asc')
            ->get();

        $data = $comments->map(fn ($c) => $this->comment_service->formatComment($c, true))->values()->all();

        return response()->json(['data' => $data]);
    }

    public function store(StoreOrderSessionCommentRequest $request, string $session_id): JsonResponse
    {
        $user          = auth()->user();
        $owner_user_id = $this->comment_service->findSessionOwnerUserId($session_id);

        if ($owner_user_id === null) {
            return response()->json(['message' => 'Order session not found.'], 404);
        }

        if ($user->id !== $owner_user_id && !$this->comment_service->isAdminOrStaff($user)) {
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

        $comment = OrderSessionComment::create([
            'session_id' => $session_id,
            'user_id'    => $user->id,
            'parent_id'  => $parent_id,
            'content'    => $validated['content'],
        ]);

        $comment->load(['user.roles']);

        $this->comment_service->notifyOnNewComment($comment, $user, $this->notification_service);

        return response()->json(
            ['data' => $this->comment_service->formatComment($comment)],
            201
        );
    }
}
