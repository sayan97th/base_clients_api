<?php

namespace App\Http\Requests\OrderSessionComment;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOrderSessionCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'content' => ['required', 'string', 'min:1', 'max:5000'],
        ];
    }
}
