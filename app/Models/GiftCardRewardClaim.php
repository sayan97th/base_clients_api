<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A gift card the client earned by checking out with a gift card reward
 * coupon. Created at checkout, completed by the client (email + brand) and
 * marked as fulfilled by an admin once the gift card has been sent.
 */
class GiftCardRewardClaim extends Model
{
    use HasUuids;

    public const STATUS_PENDING_DETAILS = 'pending_details';
    public const STATUS_SUBMITTED       = 'submitted';
    public const STATUS_FULFILLED       = 'fulfilled';

    public const SOURCE_CHECKOUT   = 'checkout';
    public const SOURCE_ORDER_PAGE = 'order_page';

    /** Gift card brands the client can choose from, keyed by stored value. */
    public const BRANDS = [
        'amazon' => 'Amazon',
        'visa'   => 'Visa',
        'target' => 'Target',
    ];

    protected $fillable = [
        'coupon_id',
        'user_id',
        'session_id',
        'order_ids',
        'product_types',
        'coupon_code',
        'order_total',
        'reward_percentage',
        'reward_amount',
        'recipient_email',
        'gift_card_brand',
        'submission_source',
        'status',
        'submitted_at',
        'fulfilled_at',
        'fulfilled_by',
    ];

    protected function casts(): array
    {
        return [
            'order_ids'         => 'array',
            'product_types'     => 'array',
            'order_total'       => 'float',
            'reward_percentage' => 'float',
            'reward_amount'     => 'float',
            'submitted_at'      => 'datetime',
            'fulfilled_at'      => 'datetime',
        ];
    }

    public static function brandKeys(): array
    {
        return array_keys(self::BRANDS);
    }

    public static function brandOptions(): array
    {
        return array_map(
            fn (string $value, string $label) => ['value' => $value, 'label' => $label],
            array_keys(self::BRANDS),
            self::BRANDS,
        );
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class, 'coupon_id')->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function fulfilledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fulfilled_by');
    }

    public function isPendingDetails(): bool
    {
        return $this->status === self::STATUS_PENDING_DETAILS;
    }

    public function brandLabel(): ?string
    {
        return $this->gift_card_brand ? (self::BRANDS[$this->gift_card_brand] ?? ucfirst($this->gift_card_brand)) : null;
    }
}
