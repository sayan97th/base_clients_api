<?php

namespace App\Http\Controllers\Admin\LinkBuilding;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LinkBuilding\UpdateLinkBuildingOrderFilterPreferenceRequest;
use App\Models\LinkBuildingOrderFilterPreference;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per user auto saved view of the admin "Link Building Orders" table. The frontend
 * writes the current filters, sorting and hidden columns here as they change, and
 * reads them back on load, so every user finds the table the way they left it.
 */
class LinkBuildingOrderFilterPreferenceController extends Controller
{
    /**
     * GET /api/admin/link-building-orders/filter-preference
     *
     * Returns `filters: null` when the user has never changed the default view.
     */
    public function show(Request $request): JsonResponse
    {
        $user       = $request->user();
        $preference = LinkBuildingOrderFilterPreference::with('appliedSavedFilter')
            ->where('user_id', $user->id)
            ->first();

        return response()->json(['data' => $this->toApiArray($preference, $user)]);
    }

    /**
     * PUT /api/admin/link-building-orders/filter-preference
     */
    public function update(UpdateLinkBuildingOrderFilterPreferenceRequest $request): JsonResponse
    {
        $user = $request->user();

        $preference = LinkBuildingOrderFilterPreference::updateOrCreate(
            ['user_id' => $user->id],
            [
                'filters'                 => $request->validated('filters'),
                'applied_saved_filter_id' => $request->validated('applied_saved_filter_id'),
            ]
        );
        $preference->load('appliedSavedFilter');

        return response()->json([
            'message' => 'Filters saved.',
            'data'    => $this->toApiArray($preference, $user),
        ]);
    }

    private function toApiArray(?LinkBuildingOrderFilterPreference $preference, User $viewer): array
    {
        $applied = $preference?->appliedSavedFilter;

        // A saved filter its owner stopped sharing is no longer "applied" for anyone else.
        $applied_is_visible = $applied
            && ((int) $applied->user_id === (int) $viewer->id || $applied->is_shared);

        return [
            'filters'                 => $preference?->filters,
            'applied_saved_filter_id' => $applied_is_visible ? $applied->id : null,
            'updated_at'              => $preference?->updated_at?->toIso8601String(),
        ];
    }
}
