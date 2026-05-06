<?php

namespace App\Http\Controllers\Admin\OrderComment;

use App\Http\Controllers\Controller;
use App\Http\Requests\OrderSessionComment\UpdateOrderSessionCommentRequest;
use App\Models\OrderSessionComment;
use App\Services\OrderSessionCommentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class AdminOrderCommentController extends Controller
{
    public function __construct(
        protected OrderSessionCommentService $comment_service,
    ) {}

    public function update(UpdateOrderSessionCommentRequest $request, OrderSessionComment $comment): JsonResponse
    {
        $comment->update(['content' => $request->validated()['content']]);

        $comment->load(['user']);

        return response()->json(['data' => $this->comment_service->formatComment($comment)]);
    }

    public function destroy(OrderSessionComment $comment): Response
    {
        $comment->delete();

        return response()->noContent();
    }
}
