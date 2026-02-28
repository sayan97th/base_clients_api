<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPreference extends Model
{
    protected $fillable = [
        'user_id',
        'timezone',
        'language',
        'interested_in',
        'email_notifications',
        'marketing_emails',
        'notification_channel',
        'team_order_updates',
        'push_notifications_enabled',
    ];

    protected function casts(): array
    {
        return [
            'email_notifications' => 'boolean',
            'marketing_emails' => 'boolean',
            'team_order_updates' => 'boolean',
            'push_notifications_enabled' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
