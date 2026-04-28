<?php

namespace App\Http\Controllers\OrderSession;

use App\Http\Controllers\Controller;
use App\Http\Requests\OrderSessionComment\UpdateOrderSessionCommentRequest;
use App\Models\OrderSessionComment;
use App\Services\OrderSessionCommentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class OrderCommentController extends Controller
{
    public function __construct(
        protected OrderSessionCommentService $comment_service,
    ) {}

    public function update(UpdateOrderSessionCommentRequest $request, OrderSessionComment $comment): JsonResponse
    {
        $user = auth()->user();

        if ($comment->user_id !== $user->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $comment->update(['content' => $request->validated()['content']]);

        $comment->load(['user.roles']);

        return response()->json(['data' => $this->comment_service->formatComment($comment)]);
    }

    public function destroy(OrderSessionComment $comment): Response|JsonResponse
    {
        $user = auth()->user();

        if ($comment->user_id !== $user->id && !$this->comment_service->isAdminOrStaff($user)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $comment->delete();

        return response()->noContent();
    }
}
