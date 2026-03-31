<?php

namespace App\Http\Controllers\Admin\Resource;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Resource\StoreResourceRequest;
use App\Http\Requests\Admin\Resource\UpdateResourceRequest;
use App\Http\Resources\AdminResourceResource;
use App\Models\Resource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AdminResourceController extends Controller
{
    /**
     * GET /api/admin/resources
     *
     * Returns a paginated list of all resources across all organizations.
     * Supports search by title, and filters by category and status.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'page'     => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
            'search'   => 'nullable|string|max:255',
            'category' => 'nullable|in:pdf,spreadsheet,document,presentation,image,blog_post,other',
            'status'   => 'nullable|in:published,draft',
        ]);

        $query = Resource::with(['files', 'organization'])
            ->orderByDesc('created_at');

        if ($request->filled('search')) {
            $query->where('title', 'like', '%' . $request->search . '%');
        }

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $per_page  = $request->per_page ?? 15;
        $paginator = $query->paginate($per_page);

        return response()->json([
            'data'         => AdminResourceResource::collection($paginator->items()),
            'current_page' => $paginator->currentPage(),
            'last_page'    => $paginator->lastPage(),
            'per_page'     => $paginator->perPage(),
            'total'        => $paginator->total(),
        ]);
    }

    /**
     * GET /api/admin/resources/{id}
     *
     * Returns the full detail of a resource including all attached files.
     * Admin can access any resource regardless of organization.
     */
    public function show(int $id): JsonResponse
    {
        $resource = Resource::with(['files', 'organization'])->find($id);

        if (! $resource) {
            return response()->json(['message' => 'Resource not found.'], 404);
        }

        return response()->json(['data' => new AdminResourceResource($resource)]);
    }

    /**
     * POST /api/admin/resources
     *
     * Creates a new resource. Files are attached via separate requests.
     */
    public function store(StoreResourceRequest $request): JsonResponse
    {
        $resource = Resource::create($request->validated());

        $resource->load(['files', 'organization']);

        return response()->json(['data' => new AdminResourceResource($resource)], 201);
    }

    /**
     * PATCH /api/admin/resources/{id}
     *
     * Updates resource metadata. All fields are optional, enabling
     * quick status toggles as well as full edits.
     */
    public function update(UpdateResourceRequest $request, int $id): JsonResponse
    {
        $resource = Resource::find($id);

        if (! $resource) {
            return response()->json(['message' => 'Resource not found.'], 404);
        }

        $resource->update($request->validated());

        $resource->load(['files', 'organization']);

        return response()->json(['data' => new AdminResourceResource($resource)]);
    }

    /**
     * DELETE /api/admin/resources/{id}
     *
     * Deletes a resource and all its attached files from storage and the database.
     */
    public function destroy(int $id): JsonResponse
    {
        $resource = Resource::with('files')->find($id);

        if (! $resource) {
            return response()->json(['message' => 'Resource not found.'], 404);
        }

        // Delete physical files from storage before removing the database records
        foreach ($resource->files as $file) {
            Storage::delete($file->file_path);
        }

        $resource->delete();

        return response()->json(null, 204);
    }
}
