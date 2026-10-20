<?php

namespace App\Http\Requests\Admin\LinkBuilding;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Partial update of a saved filter: rename it, toggle sharing, or overwrite its
 * snapshot with the table's current filters. Every field is optional.
 */
class UpdateLinkBuildingOrderSavedFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    public function rules(): array
    {
        return array_merge([
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('link_building_order_saved_filters', 'name')
                    ->where('user_id', $this->user()->id)
                    ->ignore($this->route('id')),
            ],
            'is_shared' => ['sometimes', 'boolean'],
            'filters'   => [
                'sometimes',
                'required',
                'array:' . implode(',', StoreLinkBuildingOrderSavedFilterRequest::FILTER_STATE_KEYS),
            ],
        ], StoreLinkBuildingOrderSavedFilterRequest::filterStateRules());
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'You already have a saved filter with this name.',
        ];
    }
}
