<?php

namespace App\Http\Requests\Admin\LinkBuilding;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a block of new orders created in one request, for example rows copied
 * from the BASE link sheet and pasted into the dashboard's draft rows. Every row
 * goes through the same normalization and rules as a single StoreLinkBuildingOrderRequest.
 */
class BatchStoreLinkBuildingOrdersRequest extends FormRequest
{
    /** Upper bound of rows per request, so one paste cannot create an unbounded batch. */
    public const MAX_ROWS = 500;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $rows = $this->input('rows');

        if (! is_array($rows)) {
            return;
        }

        $this->merge([
            'rows' => array_map(
                fn ($row) => is_array($row)
                    ? array_merge($row, StoreLinkBuildingOrderRequest::normalizeRowInput($row))
                    : $row,
                $rows
            ),
        ]);
    }

    public function rules(): array
    {
        $rules = [
            'rows'   => ['required', 'array', 'min:1', 'max:' . self::MAX_ROWS],
            'rows.*' => ['required', 'array'],
        ];

        foreach (StoreLinkBuildingOrderRequest::rowRules() as $field => $field_rules) {
            $rules["rows.*.{$field}"] = $field_rules;
        }

        return $rules;
    }

    /** Names the row fields as a single order does, e.g. "keyword" rather than "rows.1.keyword". */
    public function attributes(): array
    {
        $attributes = [];
        foreach (array_keys(StoreLinkBuildingOrderRequest::rowRules()) as $field) {
            $attributes["rows.*.{$field}"] = str_replace('_', ' ', $field);
        }

        return $attributes;
    }

    /**
     * The validated rows, in the order they were sent, each limited to the fields a
     * single order accepts.
     *
     * @return array<int, array<string, mixed>>
     */
    public function validatedRows(): array
    {
        return array_values($this->validated('rows'));
    }
}
