<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Resource extends Model
{
    const CATEGORIES = [
        'pdf',
        'spreadsheet',
        'document',
        'presentation',
        'image',
        'blog_post',
        'other',
    ];

    const STATUSES = [
        'published',
        'draft',
    ];

    protected $fillable = [
        'organization_id',
        'title',
        'description',
        'category',
        'status',
        'is_hidden',
    ];

    protected $casts = [
        'is_hidden' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(ResourceFile::class);
    }

    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'resource_client_assignments');
    }
}
