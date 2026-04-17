<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmeAppointment extends Model
{
    protected $fillable = [
        'user_id',
        'service_type',
        'status',
        'event_uri',
        'invitee_uri',
        'selected_tiers',
        'scheduled_at',
        'notes',
        'admin_notes',
    ];

    protected $casts = [
        'selected_tiers' => 'array',
        'scheduled_at'   => 'datetime',
    ];

    public const VALID_TRANSITIONS = [
        'pending'   => ['confirmed', 'cancelled'],
        'confirmed' => ['completed', 'cancelled'],
        'cancelled' => [],
        'completed' => [],
    ];

    public function canTransitionTo(string $newStatus): bool
    {
        return in_array($newStatus, self::VALID_TRANSITIONS[$this->status] ?? [], true);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
