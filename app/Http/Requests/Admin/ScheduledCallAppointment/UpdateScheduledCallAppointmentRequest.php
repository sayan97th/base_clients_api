<?php

namespace App\Http\Requests\Admin\ScheduledCallAppointment;

use Illuminate\Foundation\Http\FormRequest;

class UpdateScheduledCallAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status'       => ['sometimes', 'string', 'in:pending,confirmed,cancelled,completed,no_show'],
            'scheduled_at' => ['sometimes', 'nullable', 'date'],
            'notes'        => ['nullable', 'string'],
            'admin_notes'  => ['nullable', 'string'],
        ];
    }
}
