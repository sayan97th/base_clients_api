<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LinkBuildingOrderUpdate extends Model
{
    use HasUuids;

    protected $table = 'order_updates';

    protected $fillable = [
        'order_id',
        'created_by_id',
        'title',
        'message',
        'status_change',
        'send_email',
    ];

    protected function casts(): array
    {
        return [
            'send_email' => 'boolean',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(LinkBuildingOrder::class, 'order_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
