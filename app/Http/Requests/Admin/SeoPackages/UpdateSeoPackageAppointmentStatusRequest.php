<?php

namespace App\Http\Requests\Admin\SeoPackages;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSeoPackageAppointmentStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status'      => ['required', 'string', 'in:pending,confirmed,cancelled,completed'],
            'admin_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
