<?php

namespace App\Http\Controllers\Client\News;

use App\Http\Controllers\Controller;
use App\Http\Resources\NewsPostResource;
use App\Models\NewsPost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NewsController extends Controller
{
    /**
     * GET /api/news
     *
     * Returns active news posts for the client dashboard.
     * Status is always forced to 'active' — expired promos are excluded.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'per_page' => 'nullable|integer|min:1|max:20',
            'type'     => 'nullable|in:promo,news,blog_post,tip',
        ]);

        $query = NewsPost::with('coupon')
            ->where('status', 'active')
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->orderByDesc('updated_at');

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        // Exclude expired promos — posts with ends_at before today are hidden
        $query->where(function ($q) {
            $q->whereNull('ends_at')
              ->orWhere('ends_at', '>=', now()->toDateString());
        });

        $posts = $query->limit($request->per_page ?? 10)->get();

        return response()->json([
            'data' => NewsPostResource::collection($posts),
        ]);
    }
}
