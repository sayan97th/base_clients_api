<?php

namespace App\Http\Requests\Admin\LinkBuilding;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Auto saved view of the Link Building Orders table. `filters` follows the same
 * snapshot rules as a saved filter, and `applied_saved_filter_id` must point to a
 * saved filter the user can see (their own, or one shared by the team).
 */
class UpdateLinkBuildingOrderFilterPreferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $user_id = $this->user()->id;

        return array_merge([
            'filters' => [
                'present',
                'array:' . implode(',', StoreLinkBuildingOrderSavedFilterRequest::FILTER_STATE_KEYS),
            ],
            'applied_saved_filter_id' => [
                'nullable',
                'uuid',
                Rule::exists('link_building_order_saved_filters', 'id')
                    ->where(fn (Builder $query) => $query->where(
                        fn (Builder $visible) => $visible->where('user_id', $user_id)->orWhere('is_shared', true)
                    )),
            ],
        ], StoreLinkBuildingOrderSavedFilterRequest::filterStateRules());
    }

    public function messages(): array
    {
        return [
            'applied_saved_filter_id.exists' => 'The applied saved filter no longer exists or is not shared with you.',
        ];
    }
}
