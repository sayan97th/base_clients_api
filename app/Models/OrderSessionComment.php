<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderSessionComment extends Model
{
    protected $fillable = [
        'session_id',
        'order_id',
        'user_id',
        'parent_id',
        'content',
        'is_admin_comment',
    ];

    protected $appends = [
        'author_name',
        'author_avatar_url',
    ];

    protected $casts = [
        'is_admin_comment' => 'boolean',
    ];

    protected static function booted(): void
    {
        // Automatically eager-load nested replies so the tree resolves recursively.
        static::with('replies');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(OrderSessionComment::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(OrderSessionComment::class, 'parent_id')->with('replies');
    }

    public function getAuthorNameAttribute(): ?string
    {
        if (!$this->relationLoaded('user') || !$this->user) {
            return null;
        }

        return trim($this->user->first_name . ' ' . $this->user->last_name);
    }

    public function getAuthorAvatarUrlAttribute(): ?string
    {
        if (!$this->relationLoaded('user') || !$this->user) {
            return null;
        }

        return $this->user->profile_photo_url;
    }
}
