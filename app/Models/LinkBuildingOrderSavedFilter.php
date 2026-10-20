<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A named snapshot of the admin "Link Building Orders" table filters.
 *
 * `filters` holds the table state exactly as the frontend restores it: search,
 * toolbar filters, spreadsheet column filters, sort rules and hidden columns.
 * Shared filters are visible (read only) to every admin side user.
 */
class LinkBuildingOrderSavedFilter extends Model
{
    use HasUuids;

    /** Maximum number of saved filters a single user may own. */
    public const MAX_PER_USER = 50;

    protected $fillable = [
        'user_id',
        'name',
        'filters',
        'is_shared',
    ];

    protected $attributes = [
        'is_shared' => false,
    ];

    protected function casts(): array
    {
        return [
            'filters'   => 'array',
            'is_shared' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Filters owned by the given user plus every filter shared by someone else. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $q) use ($user) {
            $q->where('user_id', $user->id)->orWhere('is_shared', true);
        });
    }
}
