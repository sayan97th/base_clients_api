<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Resource extends Model
{
    protected $fillable = [
        'organization_id',
        'title',
        'description',
        'category',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(ResourceFile::class);
    }
}
