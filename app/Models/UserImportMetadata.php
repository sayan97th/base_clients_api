<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserImportMetadata extends Model
{
    protected $table = 'user_import_metadata';

    protected $fillable = [
        'user_id',
        'legacy_id',
        'google_studio_link',
        'referrer_id',
        'note',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
