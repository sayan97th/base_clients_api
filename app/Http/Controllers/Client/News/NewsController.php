<?php

namespace App\Http\Controllers\Client\News;

use App\Http\Controllers\Controller;
use App\Http\Resources\NewsPostResource;
use App\Models\NewsPost;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NewsController extends Controller
{
    /**
     * GET /api/news
     *
     * Returns a paginated list of active news posts.
     * Status is always forced to 'active' — drafts and archived posts are never exposed.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'per_page' => 'nullable|integer|min:1|max:50',
            'page'     => 'nullable|integer|min:1',
            'type'     => 'nullable|in:promo,news,blog_post,tip',
        ]);

        $per_page = min((int) $request->get('per_page', 10), 50);

        $query = NewsPost::with('coupon')
            ->where('status', 'active')
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->orderBy('sort_order', 'asc')
            ->orderBy('updated_at', 'desc');

        $paginator = $query->paginate($per_page);

        $response = $paginator->toArray();
        $response['data'] = NewsPostResource::collection($paginator->items());

        return response()->json($response);
    }

    /**
     * GET /api/news/{id}
     *
     * Returns a single active news post.
     * Returns 404 if the post does not exist or its status is not 'active'.
     */
    public function show(string $id): JsonResponse
    {
        try {
            $post = NewsPost::with('coupon')
                ->where('id', $id)
                ->where('status', 'active')
                ->firstOrFail();
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Post not found.'], 404);
        }

        return response()->json(['data' => new NewsPostResource($post)]);
    }
}
