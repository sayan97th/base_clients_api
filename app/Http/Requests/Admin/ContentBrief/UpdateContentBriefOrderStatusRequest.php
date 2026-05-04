<?php

namespace App\Http\Requests\Admin\ContentBrief;

use App\Models\ContentBriefOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateContentBriefOrderStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(ContentBriefOrder::STATUSES)],
        ];
    }
}
