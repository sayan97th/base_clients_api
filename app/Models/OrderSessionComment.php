<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderSessionComment extends Model
{
    protected $fillable = [
        'session_id',
        'user_id',
        'parent_id',
        'content',
    ];

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
        return $this->hasMany(OrderSessionComment::class, 'parent_id');
    }
}
