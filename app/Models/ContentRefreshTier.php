<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContentRefreshTier extends Model
{
    protected $table = 'content_refresh_tiers';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'label',
        'word_count_range',
        'turnaround_days',
        'price',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'turnaround_days' => 'integer',
            'price'           => 'float',
            'is_active'       => 'boolean',
            'sort_order'      => 'integer',
        ];
    }
}
