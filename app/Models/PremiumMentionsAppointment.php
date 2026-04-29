<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PremiumMentionsAppointment extends Model
{
    protected $fillable = [
        'user_id',
        'plan_id',
        'event_uri',
        'invitee_uri',
        'scheduled_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
