<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationPreference extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'notification_channel',
        'team_order_updates',
        'push_notifications_enabled',
    ];

    protected function casts(): array
    {
        return [
            'team_order_updates' => 'boolean',
            'push_notifications_enabled' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shouldSendEmail(): bool
    {
        return $this->notification_channel === 'email_and_portal';
    }
}
