<?php

namespace App\Http\Requests\Admin\PremiumMentions;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePremiumMentionsAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status'       => ['sometimes', 'string', 'in:pending,confirmed,cancelled,completed'],
            'scheduled_at' => ['sometimes', 'nullable', 'date'],
            'admin_notes'  => ['sometimes', 'nullable', 'string', 'max:2000'],
            'notes'        => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
