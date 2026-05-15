<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdminTeam extends Model
{
    use HasUuids;

    protected $table = 'admin_teams';

    protected $fillable = [
        'name',
        'description',
        'color',
        'max_capacity',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'is_active'    => 'boolean',
        'max_capacity' => 'integer',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'admin_team_user', 'admin_team_id', 'user_id')
            ->withPivot(['role', 'joined_at']);
    }

    public function placements(): HasMany
    {
        return $this->hasMany(LinkBuildingOrderPlacement::class, 'admin_team_id');
    }
}
