<?php

namespace App\Http\Controllers\Admin\LinkBuilding;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LinkBuilding\StoreLinkBuildingOrderSavedFilterRequest;
use App\Http\Requests\Admin\LinkBuilding\UpdateLinkBuildingOrderSavedFilterRequest;
use App\Models\LinkBuildingOrderSavedFilter;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Saved filter presets for the admin "Link Building Orders" table, so an admin can
 * store a combination of filters, sorting and hidden columns under a name and
 * re-apply it later in one click. Filters are private to their owner unless shared,
 * in which case every admin side user can apply them, but only the owner can edit.
 */
class LinkBuildingOrderSavedFilterController extends Controller
{
    /**
     * GET /api/admin/link-building-orders/saved-filters
     *
     * The current user's own filters first, then filters shared by other users.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $filters = LinkBuildingOrderSavedFilter::visibleTo($user)
            ->with('user:id,first_name,last_name')
            ->orderByRaw('CASE WHEN user_id = ? THEN 0 ELSE 1 END', [$user->id])
            ->orderBy('name')
            ->get()
            ->map(fn (LinkBuildingOrderSavedFilter $f) => $this->toApiArray($f, $user))
            ->values();

        return response()->json(['data' => $filters]);
    }

    /**
     * POST /api/admin/link-building-orders/saved-filters
     */
    public function store(StoreLinkBuildingOrderSavedFilterRequest $request): JsonResponse
    {
        $user = $request->user();

        $owned_count = LinkBuildingOrderSavedFilter::where('user_id', $user->id)->count();
        if ($owned_count >= LinkBuildingOrderSavedFilter::MAX_PER_USER) {
            return response()->json([
                'message' => 'You can save up to ' . LinkBuildingOrderSavedFilter::MAX_PER_USER
                    . ' filters. Delete one before saving a new filter.',
            ], 422);
        }

        $saved_filter = LinkBuildingOrderSavedFilter::create([
            'user_id'   => $user->id,
            'name'      => $request->validated('name'),
            'filters'   => $request->validated('filters'),
            'is_shared' => (bool) $request->validated('is_shared', false),
        ]);

        $saved_filter->load('user:id,first_name,last_name');

        return response()->json([
            'message' => 'Filter saved.',
            'data'    => $this->toApiArray($saved_filter, $user),
        ], 201);
    }

    /**
     * PUT /api/admin/link-building-orders/saved-filters/{id}
     *
     * Only the owner may rename, share or overwrite a filter.
     */
    public function update(UpdateLinkBuildingOrderSavedFilterRequest $request, string $id): JsonResponse
    {
        $user         = $request->user();
        $saved_filter = LinkBuildingOrderSavedFilter::where('user_id', $user->id)->findOrFail($id);

        $saved_filter->fill($request->safe()->only(['name', 'filters', 'is_shared']));
        $saved_filter->save();
        $saved_filter->load('user:id,first_name,last_name');

        return response()->json([
            'message' => 'Filter updated.',
            'data'    => $this->toApiArray($saved_filter, $user),
        ]);
    }

    /**
     * DELETE /api/admin/link-building-orders/saved-filters/{id}
     *
     * Owners can delete their own filters. Super admins may also remove filters
     * other users shared, to keep the shared list tidy.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $user  = $request->user();
        $query = LinkBuildingOrderSavedFilter::query();

        if ($user->hasRole('super_admin')) {
            $query->visibleTo($user);
        } else {
            $query->where('user_id', $user->id);
        }

        $query->findOrFail($id)->delete();

        return response()->json(['message' => 'Filter deleted.']);
    }

    private function toApiArray(LinkBuildingOrderSavedFilter $saved_filter, User $viewer): array
    {
        $owner    = $saved_filter->user;
        $is_owner = (int) $saved_filter->user_id === (int) $viewer->id;

        return [
            'id'         => $saved_filter->id,
            'name'       => $saved_filter->name,
            'filters'    => $saved_filter->filters,
            'is_shared'  => $saved_filter->is_shared,
            'is_owner'   => $is_owner,
            'can_delete' => $is_owner || ($saved_filter->is_shared && $viewer->hasRole('super_admin')),
            'owner_name' => $owner ? trim($owner->first_name . ' ' . $owner->last_name) : null,
            'created_at' => $saved_filter->created_at?->toIso8601String(),
            'updated_at' => $saved_filter->updated_at?->toIso8601String(),
        ];
    }
}
