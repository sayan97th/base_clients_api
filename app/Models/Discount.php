<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Discount extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'description',
        'discount_type',
        'discount_rate',
        'min_quantity',
        'applies_to',
        'is_active',
    ];

    protected $casts = [
        'discount_rate' => 'float',
        'min_quantity'  => 'integer',
        'is_active'     => 'boolean',
    ];
}
