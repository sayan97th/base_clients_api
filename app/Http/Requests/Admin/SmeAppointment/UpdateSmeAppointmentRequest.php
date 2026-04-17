<?php

namespace App\Http\Requests\Admin\SmeAppointment;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSmeAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status'      => ['sometimes', 'string', 'in:pending,confirmed,cancelled,completed'],
            'notes'       => ['nullable', 'string'],
            'admin_notes' => ['nullable', 'string'],
        ];
    }
}
