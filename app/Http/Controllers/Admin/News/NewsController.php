<?php

namespace App\Http\Controllers\Admin\News;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\News\StoreNewsPostRequest;
use App\Http\Requests\Admin\News\UpdateNewsPostRequest;
use App\Http\Resources\NewsPostResource;
use App\Models\NewsPost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class NewsController extends Controller
{
    /**
     * GET /api/admin/news
     */
    public function index(Request $request): JsonResponse
    {
        $query = NewsPost::with('coupon')->orderBy('sort_order')->latest();

        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('title', 'like', "%{$request->search}%")
                  ->orWhere('subtitle', 'like', "%{$request->search}%")
                  ->orWhere('description', 'like', "%{$request->search}%");
            });
        }

        if ($request->type) {
            $query->where('type', $request->type);
        }

        if ($request->status) {
            $query->where('status', $request->status);
        }

        $perPage = min((int) ($request->per_page ?? 20), 100);
        $posts   = $query->paginate($perPage);

        return response()->json([
            'data' => NewsPostResource::collection($posts->items()),
        ]);
    }

    /**
     * GET /api/admin/news/{id}
     */
    public function show(string $id): JsonResponse
    {
        $post = NewsPost::with('coupon')->find($id);

        if (!$post) {
            return response()->json(['message' => 'Post not found.'], 404);
        }

        return response()->json(['data' => new NewsPostResource($post)]);
    }

    /**
     * POST /api/admin/news
     */
    public function store(StoreNewsPostRequest $request): JsonResponse
    {
        $post = NewsPost::create($request->validated());
        $post->load('coupon');

        return response()->json(['data' => new NewsPostResource($post)], 201);
    }

    /**
     * PATCH /api/admin/news/{id}
     */
    public function update(UpdateNewsPostRequest $request, string $id): JsonResponse
    {
        $post = NewsPost::find($id);

        if (!$post) {
            return response()->json(['message' => 'Post not found.'], 404);
        }

        $post->update($request->validated());
        $post->refresh()->load('coupon');

        return response()->json(['data' => new NewsPostResource($post)]);
    }

    /**
     * DELETE /api/admin/news/{id}
     */
    public function destroy(string $id): Response|JsonResponse
    {
        $post = NewsPost::find($id);

        if (!$post) {
            return response()->json(['message' => 'Post not found.'], 404);
        }

        if ($post->image_path) {
            Storage::disk(config('filesystems.app_disk'))->delete($post->image_path);
        }

        if ($post->thumbnail_path) {
            Storage::disk(config('filesystems.app_disk'))->delete($post->thumbnail_path);
        }

        $post->delete();

        return response()->noContent();
    }

    /**
     * POST /api/admin/news/upload
     */
    public function uploadImage(Request $request): JsonResponse
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,gif,webp|max:5120',
        ]);

        $path = $request->file('image')->store('news', config('filesystems.app_disk'));
        $url  = Storage::disk(config('filesystems.app_disk'))->url($path);

        return response()->json([
            'url'  => $url,
            'path' => $path,
        ]);
    }
}
