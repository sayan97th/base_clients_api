<?php

namespace App\Http\Requests\Admin\ContentOptimization;

use App\Models\ContentOptimizationOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateContentOptimizationOrderStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(ContentOptimizationOrder::STATUSES)],
        ];
    }
}
