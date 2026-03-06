<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    public function index(): JsonResponse
    {
        $user = auth()->user();

        if ($user->hasRole('super_admin')) {
            $organizations = Organization::all();
        } else {
            $organizations = $user->organization ? [$user->organization] : [];
        }

        return response()->json(['organizations' => $organizations]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['nullable', 'string', 'max:150', 'unique:organizations'],
            'description' => ['nullable', 'string'],
            'website' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'string', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'contact_link' => ['nullable', 'string', 'max:500'],
            'logo_light' => ['nullable', 'string', 'max:500'],
            'logo_dark' => ['nullable', 'string', 'max:500'],
            'icon_light' => ['nullable', 'string', 'max:500'],
            'icon_dark' => ['nullable', 'string', 'max:500'],
            'mobile_app_icon' => ['nullable', 'string', 'max:500'],
            'primary_color' => ['nullable', 'string', 'max:7'],
            'accent_color' => ['nullable', 'string', 'max:7'],
            'timezone' => ['nullable', 'string', 'max:50'],
        ]);

        $organization = Organization::create($validated);

        return response()->json([
            'message' => 'Organization created successfully.',
            'organization' => $organization,
        ], 201);
    }

    public function show(Organization $organization): JsonResponse
    {
        return response()->json(['organization' => $organization]);
    }

    public function update(Request $request, Organization $organization): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'slug' => ['sometimes', 'string', 'max:150', 'unique:organizations,slug,' . $organization->id],
            'description' => ['nullable', 'string'],
            'website' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'string', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'contact_link' => ['nullable', 'string', 'max:500'],
            'logo_light' => ['nullable', 'string', 'max:500'],
            'logo_dark' => ['nullable', 'string', 'max:500'],
            'icon_light' => ['nullable', 'string', 'max:500'],
            'icon_dark' => ['nullable', 'string', 'max:500'],
            'mobile_app_icon' => ['nullable', 'string', 'max:500'],
            'primary_color' => ['nullable', 'string', 'max:7'],
            'accent_color' => ['nullable', 'string', 'max:7'],
            'timezone' => ['nullable', 'string', 'max:50'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $organization->update($validated);

        return response()->json([
            'message' => 'Organization updated successfully.',
            'organization' => $organization,
        ]);
    }

    public function destroy(Organization $organization): JsonResponse
    {
        $organization->delete();

        return response()->json([
            'message' => 'Organization deleted successfully.',
        ]);
    }
}
