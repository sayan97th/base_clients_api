<?php

namespace App\Http\Requests\SupportTicket;

use Illuminate\Foundation\Http\FormRequest;

class StoreSupportTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'subject' => 'required|string|max:255',
            'priority' => 'sometimes|in:low,medium,high',
            'related_order' => 'nullable|string|max:50',
            'content' => 'required|string',
        ];
    }
}
