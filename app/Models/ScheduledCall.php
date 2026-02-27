<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduledCall extends Model
{
    const CALL_TYPES = ['discovery', 'strategy', 'review', 'support'];

    const STATUSES = ['scheduled', 'completed', 'cancelled', 'no_show'];

    const DURATIONS = [15, 30, 45, 60];

    protected $fillable = [
        'contact_name',
        'contact_email',
        'call_type',
        'scheduled_date',
        'scheduled_time',
        'duration',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date',
            'duration' => 'integer',
        ];
    }
}
