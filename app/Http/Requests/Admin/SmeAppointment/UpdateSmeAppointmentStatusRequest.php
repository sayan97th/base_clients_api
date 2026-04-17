<?php

namespace App\Http\Requests\Admin\SmeAppointment;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSmeAppointmentStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status'      => ['required', 'string', 'in:pending,confirmed,cancelled,completed'],
            'admin_notes' => ['nullable', 'string'],
        ];
    }
}
