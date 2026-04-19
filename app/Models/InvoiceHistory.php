<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceHistory extends Model
{
    protected $table = 'invoice_history';

    const ACTOR_TYPES = ['system', 'client', 'admin'];

    protected $fillable = [
        'invoice_id',
        'event',
        'description',
        'actor_id',
        'actor_name',
        'actor_initials',
        'actor_type',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
