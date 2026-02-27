<?php

namespace App\Http\Requests\ScheduledCall;

use App\Models\ScheduledCall;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateScheduledCallRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'contact_name' => ['sometimes', 'string', 'max:255'],
            'contact_email' => ['sometimes', 'string', 'email', 'max:255'],
            'call_type' => ['sometimes', Rule::in(ScheduledCall::CALL_TYPES)],
            'scheduled_date' => ['sometimes', 'date', 'after_or_equal:today'],
            'scheduled_time' => ['sometimes', 'date_format:H:i'],
            'duration' => ['sometimes', 'integer', Rule::in(ScheduledCall::DURATIONS)],
            'status' => ['sometimes', Rule::in(ScheduledCall::STATUSES)],
            'notes' => ['nullable', 'string'],
        ];
    }
}
