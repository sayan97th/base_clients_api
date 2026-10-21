<?php

namespace App\Http\Requests\Admin\LinkBuilding;

use App\Models\LinkBuildingOrderSheetFormula;
use Illuminate\Foundation\Http\FormRequest;

class UpdateLinkBuildingOrderSummaryFormulaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('formula'))) {
            $formula = trim($this->input('formula'));
            $this->merge(['formula' => $formula === '' ? null : $formula]);
        }
    }

    public function rules(): array
    {
        return [
            // The column the totals cell sits under: a table column or a calculated column (formula_{id}).
            'target_key' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/'],
            // An empty formula clears the cell.
            'formula'    => [
                'nullable',
                'string',
                'max:' . LinkBuildingOrderSheetFormula::MAX_FORMULA_LENGTH,
                'regex:/^=./s',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'formula.regex' => 'A formula must start with "=".',
        ];
    }
}
