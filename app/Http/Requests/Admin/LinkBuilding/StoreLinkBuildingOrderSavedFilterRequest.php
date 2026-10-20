<?php

namespace App\Http\Requests\Admin\LinkBuilding;

use App\Http\Controllers\Admin\LinkBuilding\LinkBuildingOrdersDashboardController;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLinkBuildingOrderSavedFilterRequest extends FormRequest
{
    /** Keys allowed inside the `filters` snapshot, anything else is rejected. */
    public const FILTER_STATE_KEYS = [
        'search', 'status', 'link_type', 'client', 'client_user_id', 'assigned_user_id',
        'needs_lb_tl_approval', 'sort_rules', 'sheet_filters', 'hidden_columns', 'advanced_filters',
    ];

    /** Keys allowed on one Advanced filters rule. */
    private const ADVANCED_RULE_KEYS = ['id', 'key', 'operator', 'value', 'value_to', 'values', 'is_disabled'];

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
                'required',
                'string',
                'max:100',
                Rule::unique('link_building_order_saved_filters', 'name')
                    ->where('user_id', $this->user()->id),
            ],
            'is_shared' => ['sometimes', 'boolean'],
            'filters'   => ['required', 'array:' . implode(',', self::FILTER_STATE_KEYS)],
        ], self::filterStateRules());
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'You already have a saved filter with this name.',
        ];
    }

    /**
     * Structure of the `filters` snapshot. Column keys are only checked for shape,
     * since the search endpoint already ignores keys it does not recognize, which
     * keeps older saved filters usable when a column is renamed or removed.
     */
    public static function filterStateRules(): array
    {
        $column_key = ['string', 'max:64', 'regex:/^[a-z0-9_]+$/'];

        return [
            'filters.search'               => ['nullable', 'string', 'max:255'],
            'filters.status'               => ['nullable', 'string', 'max:100'],
            'filters.link_type'            => ['nullable', 'string', 'max:100'],
            'filters.client'               => ['nullable', 'string', 'max:255'],
            'filters.client_user_id'       => ['nullable', 'integer'],
            'filters.assigned_user_id'     => ['nullable', 'integer'],
            'filters.needs_lb_tl_approval' => ['nullable', 'boolean'],

            'filters.sort_rules'              => ['nullable', 'array', 'max:10'],
            'filters.sort_rules.*.key'        => ['required', ...$column_key],
            'filters.sort_rules.*.direction'  => ['required', Rule::in(['asc', 'desc'])],
            'filters.sort_rules.*.nulls_last' => ['sometimes', 'boolean'],

            'filters.sheet_filters' => [
                'nullable',
                'array',
                'max:50',
                function (string $attribute, mixed $value, Closure $fail) {
                    foreach (array_keys((array) $value) as $key) {
                        if (! is_string($key) || ! preg_match('/^[a-z0-9_]{1,64}$/', $key)) {
                            $fail('The column filters contain an invalid column key.');
                            return;
                        }
                    }
                },
            ],
            'filters.sheet_filters.*'                    => ['array'],
            'filters.sheet_filters.*.condition'          => ['nullable', 'array'],
            'filters.sheet_filters.*.condition.operator' => [
                'required_with:filters.sheet_filters.*.condition',
                'string',
                Rule::in(LinkBuildingOrdersDashboardController::CONDITION_OPERATORS),
            ],
            'filters.sheet_filters.*.condition.value'    => ['nullable', 'string', 'max:255'],
            'filters.sheet_filters.*.condition.value_to' => ['nullable', 'string', 'max:255'],
            'filters.sheet_filters.*.values'             => ['nullable', 'array'],
            'filters.sheet_filters.*.values.mode'        => [
                'required_with:filters.sheet_filters.*.values',
                Rule::in(['include', 'exclude']),
            ],
            'filters.sheet_filters.*.values.values'   => ['sometimes', 'array', 'max:1000'],
            'filters.sheet_filters.*.values.values.*' => ['nullable', 'string', 'max:500'],

            'filters.hidden_columns'   => ['nullable', 'array', 'max:100'],
            'filters.hidden_columns.*' => $column_key,

            'filters.advanced_filters'          => ['nullable', 'array:operator,rules,groups'],
            'filters.advanced_filters.operator' => ['sometimes', Rule::in(LinkBuildingOrdersDashboardController::ADVANCED_JOIN_OPERATORS)],
            'filters.advanced_filters.rules'    => ['sometimes', 'array', 'max:' . LinkBuildingOrdersDashboardController::ADVANCED_MAX_RULES],
            'filters.advanced_filters.groups'   => ['sometimes', 'array', 'max:' . LinkBuildingOrdersDashboardController::ADVANCED_MAX_GROUPS],
            'filters.advanced_filters.groups.*'          => ['array:id,operator,rules'],
            'filters.advanced_filters.groups.*.id'       => ['sometimes', 'string', 'max:64'],
            'filters.advanced_filters.groups.*.operator' => ['sometimes', Rule::in(LinkBuildingOrdersDashboardController::ADVANCED_JOIN_OPERATORS)],
            'filters.advanced_filters.groups.*.rules'    => ['sometimes', 'array', 'max:' . LinkBuildingOrdersDashboardController::ADVANCED_MAX_RULES],
            ...self::advancedRuleRules('filters.advanced_filters.rules.*'),
            ...self::advancedRuleRules('filters.advanced_filters.groups.*.rules.*'),
        ];
    }

    /**
     * Shape of one Advanced filters rule. A rule may be incomplete (no column or
     * value picked yet), since the panel keeps half built rules in the saved view.
     */
    private static function advancedRuleRules(string $prefix): array
    {
        return [
            $prefix                => ['array:' . implode(',', self::ADVANCED_RULE_KEYS)],
            "{$prefix}.id"         => ['sometimes', 'string', 'max:64'],
            "{$prefix}.key"        => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/'],
            "{$prefix}.operator"   => [
                'nullable',
                'string',
                Rule::in([
                    ...LinkBuildingOrdersDashboardController::CONDITION_OPERATORS,
                    ...LinkBuildingOrdersDashboardController::ADVANCED_VALUES_OPERATORS,
                ]),
            ],
            "{$prefix}.value"       => ['nullable', 'string', 'max:255'],
            "{$prefix}.value_to"    => ['nullable', 'string', 'max:255'],
            "{$prefix}.values"      => ['sometimes', 'array', 'max:1000'],
            "{$prefix}.values.*"    => ['nullable', 'string', 'max:500'],
            "{$prefix}.is_disabled" => ['sometimes', 'boolean'],
        ];
    }
}
