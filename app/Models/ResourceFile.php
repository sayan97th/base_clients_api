<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ResourceFile extends Model
{
    protected $fillable = [
        'resource_id',
        'name',
        'file_type',
        'size_bytes',
        'file_path',
    ];

    protected $appends = ['download_url'];

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    protected function downloadUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => Storage::url($this->file_path),
        );
    }
}
