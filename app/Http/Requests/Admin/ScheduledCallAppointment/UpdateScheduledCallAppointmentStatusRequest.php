<?php

namespace App\Http\Requests\Admin\ScheduledCallAppointment;

use Illuminate\Foundation\Http\FormRequest;

class UpdateScheduledCallAppointmentStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status'      => ['required', 'string', 'in:pending,confirmed,cancelled,completed,no_show'],
            'admin_notes' => ['nullable', 'string'],
        ];
    }
}
