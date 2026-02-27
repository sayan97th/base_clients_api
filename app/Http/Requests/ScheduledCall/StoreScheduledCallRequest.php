<?php

namespace App\Http\Requests\ScheduledCall;

use App\Models\ScheduledCall;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreScheduledCallRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'contact_name' => ['required', 'string', 'max:255'],
            'contact_email' => ['required', 'string', 'email', 'max:255'],
            'call_type' => ['required', Rule::in(ScheduledCall::CALL_TYPES)],
            'scheduled_date' => ['required', 'date', 'after_or_equal:today'],
            'scheduled_time' => ['required', 'date_format:H:i'],
            'duration' => ['required', 'integer', Rule::in(ScheduledCall::DURATIONS)],
            'notes' => ['nullable', 'string'],
        ];
    }
}
