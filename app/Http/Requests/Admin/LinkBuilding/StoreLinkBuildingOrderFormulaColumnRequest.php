<?php

namespace App\Http\Requests\Admin\LinkBuilding;

use App\Models\LinkBuildingOrderSheetFormula;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLinkBuildingOrderFormulaColumnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (['name', 'formula'] as $field) {
            if (is_string($this->input($field))) {
                $normalized[$field] = trim($this->input($field));
            }
        }

        if (! empty($normalized)) {
            $this->merge($normalized);
        }
    }

    public function rules(): array
    {
        return [
            'name'     => ['required', 'string', 'max:100'],
            'formula'  => self::formulaRule(),
            'format'   => ['sometimes', Rule::in(LinkBuildingOrderSheetFormula::FORMATS)],
            'position' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'formula.regex' => 'A formula must start with "=".',
        ];
    }

    /** A formula starts with "=", like in a spreadsheet. Its syntax is checked by the dashboard. */
    public static function formulaRule(bool $required = true): array
    {
        return [
            $required ? 'required' : 'sometimes',
            'string',
            'max:' . LinkBuildingOrderSheetFormula::MAX_FORMULA_LENGTH,
            'regex:/^=./s',
        ];
    }
}
