<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailNotificationSetting extends Model
{
    protected $fillable = [
        'notify_all_admins',
        'enabled_user_ids',
        'custom_emails',
    ];

    protected function casts(): array
    {
        return [
            'notify_all_admins' => 'boolean',
            'enabled_user_ids'  => 'array',
            'custom_emails'     => 'array',
        ];
    }
}
