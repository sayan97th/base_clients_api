<?php

namespace App\Http\Requests\ScheduledCallAppointment;

use Illuminate\Foundation\Http\FormRequest;

class RescheduleScheduledCallAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason'          => ['required', 'string', 'max:1000'],
            'preferred_dates' => ['nullable', 'string', 'max:500'],
        ];
    }
}
