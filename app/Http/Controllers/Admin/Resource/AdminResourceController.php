<?php

namespace App\Http\Controllers\Admin\Resource;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Resource\StoreResourceRequest;
use App\Http\Requests\Admin\Resource\UpdateResourceRequest;
use App\Http\Resources\AdminResourceResource;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AdminResourceController extends Controller
{
    private const NON_CLIENT_ROLES = ['super_admin', 'owner', 'admin', 'staff'];

    /**
     * Returns an Eloquent callback that constrains a clients eager-load
     * to only include users with the 'client' role and no admin roles.
     */
    private function clientsEagerLoadScope(): \Closure
    {
        $excluded = self::NON_CLIENT_ROLES;

        return function ($q) use ($excluded) {
            $q->whereHas('roles', function ($r) {
                $r->where('name', 'client');
            })->whereDoesntHave('roles', function ($r) use ($excluded) {
                $r->whereIn('name', $excluded);
            });
        };
    }

    /**
     * GET /api/admin/resources
     */
    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->hasPermission('resources.view')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $request->validate([
            'page'     => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
            'search'   => 'nullable|string|max:255',
            'category' => 'nullable|in:pdf,spreadsheet,document,presentation,image,blog_post,other',
            'status'   => 'nullable|in:published,draft',
        ]);

        $query = Resource::with(['files', 'organization', 'clients' => $this->clientsEagerLoadScope()])
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
     * GET /api/admin/resources/clients
     *
     * Returns a searchable list of active client users for the assignment select.
     */
    public function listClients(Request $request): JsonResponse
    {
        if (! $request->user()->hasPermission('resources.view')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $search = $request->query('search', '');

        $excluded = self::NON_CLIENT_ROLES;

        $query = User::whereHas('roles', function ($q) {
            $q->where('name', 'client');
        })->whereDoesntHave('roles', function ($q) use ($excluded) {
            $q->whereIn('name', $excluded);
        })->select('id', 'first_name', 'last_name', 'email', 'is_active');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $clients = $query->orderByDesc('is_active')->orderBy('first_name')->orderBy('last_name')->get();

        return response()->json([
            'data' => $clients->map(fn ($u) => [
                'id'        => $u->id,
                'name'      => trim("{$u->first_name} {$u->last_name}"),
                'email'     => $u->email,
                'is_active' => (bool) $u->is_active,
            ]),
        ]);
    }

    /**
     * GET /api/admin/resources/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->hasPermission('resources.show')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $resource = Resource::with(['files', 'organization', 'clients' => $this->clientsEagerLoadScope()])->find($id);

        if (! $resource) {
            return response()->json(['message' => 'Resource not found.'], 404);
        }

        return response()->json(['data' => new AdminResourceResource($resource)]);
    }

    /**
     * POST /api/admin/resources
     */
    public function store(StoreResourceRequest $request): JsonResponse
    {
        if (! $request->user()->hasPermission('resources.create')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $resource = Resource::create($request->safe()->except(['client_ids']));

        if ($request->has('client_ids')) {
            $resource->clients()->sync($request->input('client_ids', []));
        }

        $resource->load(['files', 'organization', 'clients' => $this->clientsEagerLoadScope()]);

        return response()->json(['data' => new AdminResourceResource($resource)], 201);
    }

    /**
     * PATCH /api/admin/resources/{id}
     */
    public function update(UpdateResourceRequest $request, int $id): JsonResponse
    {
        $only_status       = $request->keys() === ['status'];
        $required_permission = $only_status ? 'resources.publish' : 'resources.edit';

        if (! $request->user()->hasPermission($required_permission)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $resource = Resource::find($id);

        if (! $resource) {
            return response()->json(['message' => 'Resource not found.'], 404);
        }

        $resource->update($request->safe()->except(['client_ids']));

        if ($request->has('client_ids')) {
            $resource->clients()->sync($request->input('client_ids', []));
        }

        $resource->load(['files', 'organization', 'clients' => $this->clientsEagerLoadScope()]);

        return response()->json(['data' => new AdminResourceResource($resource)]);
    }

    /**
     * DELETE /api/admin/resources/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->hasPermission('resources.delete')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $resource = Resource::with('files')->find($id);

        if (! $resource) {
            return response()->json(['message' => 'Resource not found.'], 404);
        }

        foreach ($resource->files as $file) {
            Storage::disk(config('filesystems.app_disk'))->delete($file->file_path);
        }

        $resource->delete();

        return response()->json(null, 204);
    }
}
