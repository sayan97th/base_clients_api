<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoPackageSubscription extends Model
{
    const STATUSES = ['active', 'cancelled', 'expired'];

    protected $fillable = [
        'user_id',
        'seo_package_id',
        'status',
        'starts_at',
        'ends_at',
        'cancelled_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'starts_at'    => 'date',
            'ends_at'      => 'date',
            'cancelled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(SeoPackage::class, 'seo_package_id');
    }
}
