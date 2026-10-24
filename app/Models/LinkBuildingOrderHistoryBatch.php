<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Summary of one action of the Link Building Orders edit history, kept up to
 * date by LinkBuildingOrderHistoryRecorder. The Version history list reads these
 * rows only, the revisions of an action load when it is opened.
 */
class LinkBuildingOrderHistoryBatch extends Model
{
    use MassPrunable;

    public $timestamps = false;

    /** Order IDs kept with the summary, so an action can be recognized without opening it. */
    public const ORDER_IDS_PREVIEW_SIZE = 5;

    protected $fillable = [
        'batch_id',
        'source',
        'user_id',
        'restored_batch_id',
        'revisions_count',
        'rows_count',
        'cells_count',
        'created_count',
        'updated_count',
        'deleted_count',
        'order_ids_preview',
        'started_at',
        'last_change_at',
        'restored_at',
        'restored_by_id',
    ];

    protected function casts(): array
    {
        return [
            'order_ids_preview' => 'array',
            'started_at'        => 'datetime',
            'last_change_at'    => 'datetime',
            'restored_at'       => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function restoredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'restored_by_id');
    }

    public function prunable(): Builder
    {
        return static::where('last_change_at', '<', now()->subDays(config('link_building.history_retention_days')));
    }

    /** API shape of one entry of the Version history list. */
    public function toApiArray(): array
    {
        return [
            'id'                => $this->id,
            'batch_id'          => $this->batch_id,
            'source'            => $this->source,
            'user'              => self::userSummary($this->relationLoaded('user') ? $this->user : null),
            'started_at'        => $this->started_at?->toIso8601String(),
            'revisions_count'   => $this->revisions_count,
            'rows_count'        => $this->rows_count,
            'cells_count'       => $this->cells_count,
            'created_count'     => $this->created_count,
            'updated_count'     => $this->updated_count,
            'deleted_count'     => $this->deleted_count,
            'order_ids_preview' => $this->order_ids_preview ?? [],
            'restored_batch_id' => $this->restored_batch_id,
            'restored'          => $this->restored_at ? [
                'restored_at' => $this->restored_at->toIso8601String(),
                'user'        => self::userSummary($this->relationLoaded('restoredBy') ? $this->restoredBy : null),
            ] : null,
        ];
    }

    /** @return array{id: int, name: string}|null */
    public static function userSummary(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id'   => $user->id,
            'name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: $user->email,
        ];
    }
}
