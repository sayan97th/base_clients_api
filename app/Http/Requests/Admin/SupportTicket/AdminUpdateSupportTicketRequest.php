<?php

namespace App\Http\Requests\Admin\SupportTicket;

use App\Models\SupportTicket;
use Illuminate\Foundation\Http\FormRequest;

class AdminUpdateSupportTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status'      => ['sometimes', 'string', 'in:' . implode(',', SupportTicket::STATUSES)],
            'priority'    => ['sometimes', 'string', 'in:' . implode(',', SupportTicket::PRIORITIES)],
            'assigned_to' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
        ];
    }
}
