<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutopaySetting extends Model
{
    use HasUuids;

    public const DISABLED_BY_TYPES = ['client', 'admin', 'system'];

    /**
     * Authorization the client accepts when turning autopay on. The exact text
     * accepted is stored on the row (consent_text) for auditing.
     */
    public const CONSENT_TEXT = 'I authorize BASE Search Marketing to automatically charge the selected card for the full amount '
        . 'of each new invoice issued to my account, on or after its due date, until I turn off autopay. '
        . 'Invoices that already exist today are not included. If a charge fails, it may be retried up to 3 times. '
        . 'I can change the card or turn off autopay at any time.';

    protected $fillable = [
        'user_id',
        'payment_profile_id',
        'is_enabled',
        'max_amount',
        'consent_text',
        'consent_ip',
        'consent_user_agent',
        'enabled_at',
        'disabled_at',
        'disabled_by_type',
        'disabled_by_name',
        'disabled_reason',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled'  => 'boolean',
            'max_amount'  => 'float',
            'enabled_at'  => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paymentProfile(): BelongsTo
    {
        return $this->belongsTo(PaymentProfile::class);
    }

    /**
     * Turn autopay off and record who did it and why.
     */
    public function disable(string $disabled_by_type, ?string $disabled_by_name, ?string $reason = null): void
    {
        $this->update([
            'is_enabled'       => false,
            'disabled_at'      => now(),
            'disabled_by_type' => $disabled_by_type,
            'disabled_by_name' => $disabled_by_name,
            'disabled_reason'  => $reason,
        ]);
    }
}
