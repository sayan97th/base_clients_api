<?php

namespace App\Http\Requests\SeoPackages;

use Illuminate\Foundation\Http\FormRequest;

class StoreSeoPackageAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'event_uri'   => ['required', 'string', 'max:500'],
            'invitee_uri' => ['required', 'string', 'max:500'],
            'package_id'  => ['required', 'string'],
        ];
    }
}
