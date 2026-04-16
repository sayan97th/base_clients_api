<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmeAuthoredTier extends Model
{
    protected $fillable = [
        'tier_key',
        'label',
        'description',
        'price',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
