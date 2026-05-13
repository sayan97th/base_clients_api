<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditPackage extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'name',
        'credits',
        'price',
        'original_price',
        'discount_pct',
        'description',
        'is_popular',
        'is_active',
    ];

    protected $casts = [
        'credits'        => 'integer',
        'price'          => 'decimal:2',
        'original_price' => 'decimal:2',
        'discount_pct'   => 'integer',
        'is_popular'     => 'boolean',
        'is_active'      => 'boolean',
    ];
}
