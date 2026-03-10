<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceBilledTo extends Model
{
    use HasUuids;

    protected $table = 'invoice_billed_to';

    protected $fillable = [
        'invoice_id',
        'company_name',
        'company_description',
        'address_line_1',
        'address_line_2',
        'state',
        'country',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
