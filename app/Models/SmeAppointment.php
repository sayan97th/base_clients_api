<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmeAppointment extends Model
{
    protected $fillable = [
        'user_id',
        'service_type',
        'event_uri',
        'invitee_uri',
        'selected_tiers',
        'scheduled_at',
    ];

    protected $casts = [
        'selected_tiers' => 'array',
        'scheduled_at'   => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
