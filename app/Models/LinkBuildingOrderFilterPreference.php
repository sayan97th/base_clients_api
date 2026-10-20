<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The current view of the admin "Link Building Orders" table for one user, saved
 * automatically so filters survive reloads and new sessions. `filters` uses the
 * same snapshot shape as LinkBuildingOrderSavedFilter::$filters.
 */
class LinkBuildingOrderFilterPreference extends Model
{
    protected $fillable = [
        'user_id',
        'filters',
        'applied_saved_filter_id',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function appliedSavedFilter(): BelongsTo
    {
        return $this->belongsTo(LinkBuildingOrderSavedFilter::class, 'applied_saved_filter_id');
    }
}
