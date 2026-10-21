<?php

namespace App\Http\Requests\Admin\LinkBuilding;

use App\Models\LinkBuildingOrderSheetFormula;
use Illuminate\Validation\Rule;

class UpdateLinkBuildingOrderFormulaColumnRequest extends StoreLinkBuildingOrderFormulaColumnRequest
{
    public function rules(): array
    {
        return [
            'name'     => ['sometimes', 'required', 'string', 'max:100'],
            'formula'  => self::formulaRule(required: false),
            'format'   => ['sometimes', Rule::in(LinkBuildingOrderSheetFormula::FORMATS)],
            'position' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ];
    }
}
