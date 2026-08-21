<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailInterceptSetting extends Model
{
    protected $fillable = [
        'intercept_admin_emails',
        'intercept_client_emails',
        'recipient_emails',
    ];

    protected $attributes = [
        'intercept_admin_emails'  => false,
        'intercept_client_emails' => false,
        'recipient_emails'        => '[]',
    ];

    protected function casts(): array
    {
        return [
            'intercept_admin_emails'  => 'boolean',
            'intercept_client_emails' => 'boolean',
            'recipient_emails'        => 'array',
        ];
    }
}
