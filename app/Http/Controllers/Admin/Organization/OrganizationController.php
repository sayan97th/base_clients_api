<?php

namespace App\Http\Controllers\Admin\Organization;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrganizationController extends Controller
{
    /**
     * GET /api/admin/organizations?page=N
     */
    public function index(Request $request): JsonResponse
    {
        $organizations = Organization::latest()->paginate(15);

        return response()->json([
            'data' => $organizations->items(),
            'current_page' => $organizations->currentPage(),
            'last_page' => $organizations->lastPage(),
            'total' => $organizations->total(),
        ]);
    }

    /**
     * GET /api/admin/organizations/{id}
     */
    public function show(int $id): JsonResponse
    {
        $organization = Organization::find($id);

        if (! $organization) {
            return response()->json(['message' => 'Organization not found.'], 404);
        }

        return response()->json($organization);
    }

    /**
     * PUT /api/admin/organizations/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $organization = Organization::find($id);

        if (! $organization) {
            return response()->json(['message' => 'Organization not found.'], 404);
        }

        $validated = $request->validate([
            'name'           => ['required', 'string', 'max:150'],
            'description'    => ['nullable', 'string', 'max:5000'],
            'website'        => ['nullable', 'url', 'max:255'],
            'contact_email'  => ['nullable', 'email', 'max:255'],
            'contact_phone'  => ['nullable', 'string', 'max:30'],
            'contact_link'   => ['nullable', 'url', 'max:500'],
            'logo_light'     => ['nullable', 'url', 'max:500'],
            'logo_dark'      => ['nullable', 'url', 'max:500'],
            'icon_light'     => ['nullable', 'url', 'max:500'],
            'icon_dark'      => ['nullable', 'url', 'max:500'],
            'mobile_app_icon' => ['nullable', 'url', 'max:500'],
            'primary_color'  => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'accent_color'   => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'timezone'       => ['required', 'string', Rule::in(\DateTimeZone::listIdentifiers())],
            'is_active'      => ['required', 'boolean'],
        ]);

        $organization->update($validated);

        return response()->json($organization->fresh());
    }
}