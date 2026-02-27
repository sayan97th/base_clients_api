<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupportTicket extends Model
{
    use HasFactory, SoftDeletes;

    const STATUSES = ['open', 'in_progress', 'resolved', 'closed'];
    const PRIORITIES = ['low', 'medium', 'high'];

    protected $fillable = [
        'ticket_number',
        'subject',
        'status',
        'priority',
        'related_order',
        'user_id',
        'closed_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'closed_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (SupportTicket $ticket) {
            if (empty($ticket->ticket_number)) {
                $ticket->ticket_number = self::generateTicketNumber();
            }
        });
    }

    public static function generateTicketNumber(): string
    {
        $last_ticket = self::withTrashed()->orderBy('id', 'desc')->first();
        $next_number = $last_ticket ? ((int) str_replace('TKT-', '', $last_ticket->ticket_number)) + 1 : 1;

        return 'TKT-' . str_pad($next_number, 3, '0', STR_PAD_LEFT);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class, 'ticket_id');
    }
}
