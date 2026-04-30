<?php

namespace App\Http\Requests\Admin\SeoPackages;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSeoPackageAppointmentRequest extends FormRequest
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
            'admin_notes'  => ['sometimes', 'nullable', 'string', 'max:5000'],
            'notes'        => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
