<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseGroup extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'purchase_group_id',
        'user_id',
        'order_title',
        'total_amount',
        'payment_status',
        'invoice_unique_id',
        'payment_method',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'float',
            'created_at'   => 'datetime',
            'updated_at'   => 'datetime',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(PurchaseGroupOrder::class, 'purchase_group_id', 'purchase_group_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
