<?php

namespace App\Http\Controllers\Admin\Resource;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Resource\StoreResourceFileRequest;
use App\Http\Resources\ResourceFileResource;
use App\Models\Resource;
use App\Models\ResourceFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AdminResourceFileController extends Controller
{
    /**
     * POST /api/admin/resources/{id}/files
     *
     * Uploads a single file and attaches it to the given resource.
     * One request per file.
     */
    public function store(StoreResourceFileRequest $request, int $id): JsonResponse
    {
        if (! $request->user()->hasPermission('resources.manage_files')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $resource = Resource::find($id);

        if (! $resource) {
            return response()->json(['message' => 'Resource not found.'], 404);
        }

        $uploaded  = $request->file('file');
        $file_path = $uploaded->store("resources/{$id}", 'public');

        $resource_file = ResourceFile::create([
            'resource_id' => $resource->id,
            'name'        => $uploaded->getClientOriginalName(),
            'file_type'   => $uploaded->getClientOriginalExtension(),
            'size_bytes'  => $uploaded->getSize(),
            'file_path'   => $file_path,
        ]);

        return response()->json(['data' => new ResourceFileResource($resource_file)], 201);
    }

    /**
     * DELETE /api/admin/resources/{id}/files/{file_id}
     *
     * Deletes a specific file attachment from both storage and the database.
     * Returns 404 if the file does not belong to the given resource.
     */
    public function destroy(Request $request, int $id, int $file_id): JsonResponse
    {
        if (! $request->user()->hasPermission('resources.manage_files')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $resource_file = ResourceFile::where('id', $file_id)
            ->where('resource_id', $id)
            ->first();

        if (! $resource_file) {
            return response()->json(['message' => 'File not found.'], 404);
        }

        Storage::disk('public')->delete($resource_file->file_path);

        $resource_file->delete();

        return response()->json(null, 204);
    }
}
