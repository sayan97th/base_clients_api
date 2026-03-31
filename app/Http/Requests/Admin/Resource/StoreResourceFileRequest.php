<?php

namespace App\Http\Requests\Admin\Resource;

use Illuminate\Foundation\Http\FormRequest;

class StoreResourceFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:pdf,xlsx,xls,csv,docx,doc,pptx,ppt,png,jpg,jpeg,gif,webp',
                'max:51200', // 50 MB in kilobytes
            ],
        ];
    }
}
