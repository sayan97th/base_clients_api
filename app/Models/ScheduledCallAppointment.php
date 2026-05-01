<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledCallAppointment extends Model
{
    const STATUSES = ['pending', 'confirmed', 'cancelled', 'completed', 'no_show'];

    const CANCELLABLE_STATUSES = ['pending', 'confirmed'];

    const RESCHEDULE_ALLOWED_STATUSES = ['pending', 'confirmed'];

    protected $fillable = [
        'user_id',
        'event_uri',
        'invitee_uri',
        'status',
        'scheduled_at',
        'notes',
        'admin_notes',
        'cancellation_reason',
        'reschedule_reason',
        'preferred_dates',
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

    public function isCancellable(): bool
    {
        return in_array($this->status, self::CANCELLABLE_STATUSES);
    }

    public function canRequestReschedule(): bool
    {
        return in_array($this->status, self::RESCHEDULE_ALLOWED_STATUSES);
    }
}
