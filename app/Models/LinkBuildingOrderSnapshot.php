<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Read only copy of a Link Building order as the client submitted it, written by
 * LinkBuildingOrderSnapshotService. Once saved it can never be changed or
 * deleted on its own (only together with its order), so it stays a reliable
 * record of the original request no matter how the live rows are edited.
 */
class LinkBuildingOrderSnapshot extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    public const TYPE_PLACED            = 'placed';
    public const TYPE_DETAILS_SUBMITTED = 'details_submitted';

    /** Payment method labels stored with a "placed" snapshot. */
    public const PAYMENT_CREDIT_CARD        = 'Credit Card';
    public const PAYMENT_ACCOUNT_BALANCE    = 'Account Balance';
    public const PAYMENT_CARD_AND_BALANCE   = 'Credit Card + Account Balance';
    public const PAYMENT_PAY_LATER_INVOICE  = 'Pay Later (Invoice)';

    protected $fillable = [
        'order_id',
        'type',
        'payment_method',
        'payload',
        'is_backfilled',
        'captured_by_id',
    ];

    protected function casts(): array
    {
        return [
            'payload'       => 'array',
            'is_backfilled' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Original order snapshots are read only.');
        });

        static::deleting(function () {
            throw new LogicException('Original order snapshots cannot be deleted.');
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(LinkBuildingOrder::class, 'order_id');
    }

    public function capturedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by_id');
    }
}
