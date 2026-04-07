<?php

namespace App\Http\Requests\Admin\NewsPlacement;

use Illuminate\Foundation\Http\FormRequest;

class UpdateNewsPlacementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'domain'              => 'required|string|max:255',
            'dr'                  => 'nullable|string|max:10',
            'traffic'             => 'nullable|string|max:20',
            'category'            => 'nullable|string|max:100',
            'price'               => 'nullable|string|max:50',
            'types_of_content'    => 'nullable|string|max:100',
            'do_follow_no_follow' => 'nullable|string|max:50',
            'indexable'           => 'nullable|string|max:10',
            'well_known_site'     => 'nullable|string|max:100',
            'links_allowed'       => 'nullable|string|max:20',
            'additional_notes'    => 'nullable|string|max:1000',
            'price_1'             => 'nullable|string|max:50',
            'poc_1'               => 'nullable|string|max:150',
            'price_2'             => 'nullable|string|max:50',
            'poc_2'               => 'nullable|string|max:150',
            'tier'                => 'nullable|string|max:100',
            'pbn_check'           => 'nullable|string|max:20',
            'used_domain'         => 'nullable|string|max:10',
            'within_budget'       => 'nullable|string|max:10',
            'ref_domains'         => 'nullable|string|max:20',
        ];
    }
}
