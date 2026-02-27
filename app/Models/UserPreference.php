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
    ];

    protected function casts(): array
    {
        return [
            'email_notifications' => 'boolean',
            'marketing_emails' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
