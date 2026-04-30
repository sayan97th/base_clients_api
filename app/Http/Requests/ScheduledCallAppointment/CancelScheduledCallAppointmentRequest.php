<?php

namespace App\Http\Requests\ScheduledCallAppointment;

use Illuminate\Foundation\Http\FormRequest;

class CancelScheduledCallAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
