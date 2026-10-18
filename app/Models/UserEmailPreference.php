<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserEmailPreference extends Model
{
    protected $fillable = [
        'user_id',
        'mute_all_emails',
        'disabled_email_types',
        'updated_by_user_id',
    ];

    protected $attributes = [
        'mute_all_emails'      => false,
        'disabled_email_types' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'mute_all_emails'      => 'boolean',
            'disabled_email_types' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
