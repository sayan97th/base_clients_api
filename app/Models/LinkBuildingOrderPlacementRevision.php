<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change to a row of the Link Building Orders table, written by
 * LinkBuildingOrderHistoryRecorder. See the create migration for the columns.
 */
class LinkBuildingOrderPlacementRevision extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    public const EVENT_CREATED = 'created';
    public const EVENT_UPDATED = 'updated';
    public const EVENT_DELETED = 'deleted';

    /** Where a change came from, shown in the version history. */
    public const SOURCES = [
        'edit', 'paste', 'clear', 'fill', 'undo', 'redo', 'formula', 'create', 'delete',
        'clear_all', 'import', 'metrics_import', 'auto_assign', 'restore', 'checkout',
        'client_details', 'admin_details', 'system',
    ];

    /** Sources the dashboard may send with a batch update, the rest are set by the server. */
    public const CLIENT_SOURCES = ['edit', 'paste', 'clear', 'fill', 'undo', 'redo', 'formula'];

    /** Days a revision is kept before `php artisan model:prune` removes it. */
    public const RETENTION_DAYS = 365;

    protected $fillable = [
        'batch_id',
        'placement_id',
        'order_id',
        'event',
        'source',
        'cell_changes',
        'snapshot',
        'restored_batch_id',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'cell_changes' => 'array',
            'snapshot'     => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(self::RETENTION_DAYS));
    }

    /** API shape used by the version history panel. */
    public function toApiArray(): array
    {
        return [
            'id'           => $this->id,
            'batch_id'     => $this->batch_id,
            'placement_id' => $this->placement_id,
            'order_id'     => $this->order_id,
            'event'        => $this->event,
            'source'       => $this->source,
            'changes'      => collect($this->cell_changes ?? [])
                ->map(fn (array $change, string $field) => [
                    'field' => $field,
                    'old'   => $change['old'] ?? null,
                    'new'   => $change['new'] ?? null,
                ])
                ->values()
                ->all(),
            'snapshot'     => $this->snapshot,
            'user'         => $this->userSummary(),
            'created_at'   => $this->created_at?->toIso8601String(),
        ];
    }

    /** @return array{id: int, name: string}|null */
    public function userSummary(): ?array
    {
        if (! $this->relationLoaded('user') || ! $this->user) {
            return null;
        }

        return [
            'id'   => $this->user->id,
            'name' => trim(($this->user->first_name ?? '') . ' ' . ($this->user->last_name ?? '')) ?: $this->user->email,
        ];
    }
}
