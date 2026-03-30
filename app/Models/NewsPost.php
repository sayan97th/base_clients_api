<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NewsPost extends Model
{
    use HasUuids;

    protected $fillable = [
        'type',
        'status',
        'title',
        'subtitle',
        'description',
        'discount_value',
        'discount_label',
        'coupon_id',
        'starts_at',
        'ends_at',
        'image_url',
        'image_path',
        'thumbnail_url',
        'thumbnail_path',
        'cta_text',
        'cta_url',
        'tags',
        'is_featured',
        'sort_order',
    ];

    protected $casts = [
        'tags'        => 'array',
        'is_featured' => 'boolean',
        'sort_order'  => 'integer',
        'starts_at'   => 'date',
        'ends_at'     => 'date',
    ];

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }
}
