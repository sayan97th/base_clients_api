<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PremiumMentionsAppointment extends Model
{
    use SoftDeletes;

    const STATUS_TRANSITIONS = [
        'pending'   => ['confirmed', 'cancelled'],
        'confirmed' => ['completed', 'cancelled'],
        'cancelled' => [],
        'completed' => [],
    ];

    protected $fillable = [
        'user_id',
        'plan_id',
        'event_uri',
        'invitee_uri',
        'status',
        'scheduled_at',
        'notes',
        'admin_notes',
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

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PremiumMentionsPlan::class, 'plan_id');
    }

    public function canTransitionTo(string $new_status): bool
    {
        return in_array($new_status, self::STATUS_TRANSITIONS[$this->status] ?? []);
    }
}
