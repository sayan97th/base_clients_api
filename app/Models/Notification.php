<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class Notification extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'message',
        'preview_text',
        'link',
        'resource_type',
        'resource_id',
        'metadata',
        'is_read',
        'is_archived',
        'is_snoozed',
        'read_at',
        'snoozed_until',
    ];

    protected $appends = ['date', 'relative_time'];

    protected function casts(): array
    {
        return [
            'is_read' => 'boolean',
            'is_archived' => 'boolean',
            'is_snoozed' => 'boolean',
            'read_at' => 'datetime',
            'snoozed_until' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function getDateAttribute(): string
    {
        return $this->created_at->format("M jS 'y \\a\\t g:i a");
    }

    public function getRelativeTimeAttribute(): string
    {
        return $this->created_at->diffForHumans();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->where('is_read', false);
    }

    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->where('is_archived', false);
    }

    public function scopeNotSnoozed(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->where('is_snoozed', false)
              ->orWhere('snoozed_until', '<=', now());
        });
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    public function scopeForUser(Builder $query, int $user_id): Builder
    {
        return $query->where('user_id', $user_id);
    }

    /**
     * Restricts to notifications addressed to an admin/staff recipient. The admin
     * notification feed previously queried every row regardless of recipient, so a
     * client's own notifications (e.g. "Your order has been placed", or the client's
     * copy of an order_comment reply, both carrying client-portal links with no
     * "/admin" prefix) leaked into the admin feed. Clicking one there navigated the
     * staff user to a client-only route, which the app's route guard always bounces
     * back to /admin/dashboard, so every leaked notification looked "broken".
     */
    public function scopeForAdminAudience(Builder $query): Builder
    {
        return $query->whereHas(
            'user.roles',
            fn (Builder $q) => $q->whereIn('name', ['super_admin', 'admin', 'staff'])
        );
    }

    public function markAsRead(): void
    {
        $this->update([
            'is_read' => true,
            'read_at' => now(),
        ]);
    }

    public function markAsUnread(): void
    {
        $this->update([
            'is_read' => false,
            'read_at' => null,
        ]);
    }

    public function archive(): void
    {
        $this->update([
            'is_archived' => true,
        ]);
    }

    public function unarchive(): void
    {
        $this->update([
            'is_archived' => false,
        ]);
    }

    public function snooze(\DateTimeInterface $until): void
    {
        $this->update([
            'is_snoozed' => true,
            'snoozed_until' => $until,
        ]);
    }

    public function unsnooze(): void
    {
        $this->update([
            'is_snoozed' => false,
            'snoozed_until' => null,
        ]);
    }
}
