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
     * Returns a paginated list of resources scoped to the authenticated
     * user's organization. Supports optional search and category filters.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'page'     => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
            'search'   => 'nullable|string|max:255',
            'category' => 'nullable|in:pdf,spreadsheet,document,presentation,image,blog_post,other',
        ]);

        $organization_id = auth()->user()->organization_id;

        $query = Resource::with('files')
            ->where('organization_id', $organization_id)
            ->where('status', 'published')
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
     * Returns the full detail of a single resource including all attached files.
     * Returns 404 if the resource does not exist or belongs to a different organization.
     */
    public function show(int $id): JsonResponse
    {
        $organization_id = auth()->user()->organization_id;

        $resource = Resource::with('files')
            ->where('id', $id)
            ->where('organization_id', $organization_id)
            ->where('status', 'published')
            ->first();

        if (! $resource) {
            return response()->json(['message' => 'Resource not found.'], 404);
        }

        return response()->json(new ResourceResource($resource));
    }
}
