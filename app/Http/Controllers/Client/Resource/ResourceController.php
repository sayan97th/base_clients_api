<?php

namespace App\Http\Controllers\Client\Resource;

use App\Http\Controllers\Controller;
use App\Http\Resources\ResourceResource;
use App\Models\Resource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResourceController extends Controller
{
    /**
     * GET /api/resources
     *
     * Returns published, non-hidden resources visible to the authenticated user.
     * A resource is visible when:
     *   - it has no specific client assignments (public to all), OR
     *   - the current user is explicitly assigned to it.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'page'     => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
            'search'   => 'nullable|string|max:255',
            'category' => 'nullable|in:pdf,spreadsheet,document,presentation,image,blog_post,other',
        ]);

        $user_id = auth()->id();

        $query = Resource::with('files')
            ->where('status', 'published')
            ->where('is_hidden', false)
            ->where(function ($q) use ($user_id) {
                $q->whereDoesntHave('clients')
                  ->orWhereHas('clients', fn ($q2) => $q2->where('users.id', $user_id));
            })
            ->orderByDesc('created_at');

        if ($request->filled('search')) {
            $query->where('title', 'like', '%' . $request->search . '%');
        }

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        $per_page  = $request->per_page ?? 12;
        $paginator = $query->paginate($per_page);

        return response()->json([
            'data'         => ResourceResource::collection($paginator->items()),
            'current_page' => $paginator->currentPage(),
            'last_page'    => $paginator->lastPage(),
            'per_page'     => $paginator->perPage(),
            'total'        => $paginator->total(),
        ]);
    }

    /**
     * GET /api/resources/{id}
     *
     * Returns a single published, non-hidden resource if the user has access.
     */
    public function show(int $id): JsonResponse
    {
        $user_id = auth()->id();

        $resource = Resource::with('files')
            ->where('id', $id)
            ->where('status', 'published')
            ->where('is_hidden', false)
            ->where(function ($q) use ($user_id) {
                $q->whereDoesntHave('clients')
                  ->orWhereHas('clients', fn ($q2) => $q2->where('users.id', $user_id));
            })
            ->first();

        if (! $resource) {
            return response()->json(['message' => 'Resource not found.'], 404);
        }

        return response()->json(new ResourceResource($resource));
    }
}
