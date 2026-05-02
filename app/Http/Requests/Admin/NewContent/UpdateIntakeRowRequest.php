<?php

namespace App\Http\Requests\Admin\NewContent;

use Illuminate\Foundation\Http\FormRequest;

class UpdateIntakeRowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'keyword_phrase'  => ['sometimes', 'string', 'max:500'],
            'type_of_content' => ['sometimes', 'string', 'in:Blog Article,Product Page,Home Page,About Us Page,Other'],
            'notes'           => ['sometimes', 'nullable', 'string', 'max:1000'],
            'status'          => ['sometimes', 'string', 'in:pending,in_progress,completed,cancelled'],
        ];
    }
}
