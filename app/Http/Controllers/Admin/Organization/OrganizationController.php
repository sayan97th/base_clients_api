<?php

namespace App\Http\Controllers\Admin\Organization;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class OrganizationController extends Controller
{
    private const ASSET_FIELDS = [
        'logo_light',
        'logo_dark',
        'icon_light',
        'icon_dark',
        'mobile_app_icon',
    ];

    /**
     * GET /api/admin/organizations?page=N
     */
    public function index(Request $request): JsonResponse
    {
        $organizations = Organization::latest()->paginate(15);

        return response()->json([
            'data'         => collect($organizations->items())->map(fn ($org) => $this->formatOrganization($org)),
            'current_page' => $organizations->currentPage(),
            'last_page'    => $organizations->lastPage(),
            'total'        => $organizations->total(),
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

        return response()->json($this->formatOrganization($organization));
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
            'name'            => ['required', 'string', 'max:150'],
            'description'     => ['nullable', 'string', 'max:5000'],
            'website'         => ['nullable', 'url', 'max:255'],
            'contact_email'   => ['nullable', 'email', 'max:255'],
            'contact_phone'   => ['nullable', 'string', 'max:30'],
            'contact_link'    => ['nullable', 'url', 'max:500'],
            'logo_light'      => ['nullable', 'string', 'max:500'],
            'logo_dark'       => ['nullable', 'string', 'max:500'],
            'icon_light'      => ['nullable', 'string', 'max:500'],
            'icon_dark'       => ['nullable', 'string', 'max:500'],
            'mobile_app_icon' => ['nullable', 'string', 'max:500'],
            'primary_color'   => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'accent_color'    => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'timezone'        => ['required', 'string', Rule::in(\DateTimeZone::listIdentifiers())],
            'is_active'       => ['required', 'boolean'],
        ]);

        // Asset fields are managed via POST /assets.
        // Only process explicit null values here to support clearing an asset.
        $updates = array_diff_key($validated, array_flip(self::ASSET_FIELDS));

        foreach (self::ASSET_FIELDS as $field) {
            if (array_key_exists($field, $validated) && is_null($validated[$field])) {
                if ($organization->$field && Storage::disk(config('filesystems.app_disk'))->exists($organization->$field)) {
                    Storage::disk(config('filesystems.app_disk'))->delete($organization->$field);
                }
                $updates[$field] = null;
            }
        }

        $organization->update($updates);

        return response()->json($this->formatOrganization($organization->fresh()));
    }

    /**
     * POST /api/admin/organizations/{id}/assets
     */
    public function uploadAsset(Request $request, int $id): JsonResponse
    {
        $organization = Organization::find($id);

        if (! $organization) {
            return response()->json(['message' => 'Organization not found.'], 404);
        }

        $request->validate([
            'field' => ['required', Rule::in(self::ASSET_FIELDS)],
            'file'  => ['required', 'file', 'mimes:png,jpeg,jpg,svg,webp', 'max:5120'],
        ]);

        $field = $request->input('field');
        $file  = $request->file('file');

        // Delete the previous file from storage if one exists.
        $existing_path = $organization->$field;
        if ($existing_path && Storage::disk(config('filesystems.app_disk'))->exists($existing_path)) {
            Storage::disk(config('filesystems.app_disk'))->delete($existing_path);
        }

        // Store the new file at a deterministic path.
        $extension = $file->getClientOriginalExtension();
        $path      = $file->storeAs("organizations/{$id}", "{$field}.{$extension}", config('filesystems.app_disk'));
        $url       = Storage::disk(config('filesystems.app_disk'))->url($path);

        $organization->update([$field => $path]);

        return response()->json([
            'url'   => $url,
            'path'  => $path,
            'field' => $field,
        ]);
    }

    /**
     * Build the organization array replacing stored paths with public URLs.
     */
    private function formatOrganization(Organization $organization): array
    {
        $data = $organization->toArray();

        foreach (self::ASSET_FIELDS as $field) {
            $data[$field] = $organization->$field
                ? Storage::disk(config('filesystems.app_disk'))->url($organization->$field)
                : null;
        }

        return $data;
    }
}
