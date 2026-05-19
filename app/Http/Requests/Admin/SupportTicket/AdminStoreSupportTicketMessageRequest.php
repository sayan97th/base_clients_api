<?php

namespace App\Http\Requests\Admin\SupportTicket;

use Illuminate\Foundation\Http\FormRequest;

class AdminStoreSupportTicketMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'content' => ['required', 'string', 'max:10000'],
        ];
    }
}
