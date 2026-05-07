<?php

namespace App\Http\Requests\OrderSessionComment;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderSessionCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'content'   => ['required', 'string', 'min:1', 'max:5000'],
            'parent_id' => ['nullable', 'integer', 'exists:order_session_comments,id'],
        ];
    }
}
