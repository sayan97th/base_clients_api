<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A team wide formula of the admin "Link Building Orders" spreadsheet: either a
 * calculated column (kind "column") or one cell of the totals row under the table
 * (kind "summary", placed under the column named by target_key).
 *
 * `formula` is stored in the engine's column key notation (see the frontend's
 * src/lib/spreadsheet-formulas/references.ts), so it never depends on column letters.
 */
class LinkBuildingOrderSheetFormula extends Model
{
    public const KIND_COLUMN  = 'column';
    public const KIND_SUMMARY = 'summary';

    /** Display formats a calculated column may use. "auto" infers it from the formula. */
    public const FORMATS = ['auto', 'number', 'currency', 'percent', 'date', 'text'];

    /** Upper bound of calculated columns, so the grid stays usable. */
    public const MAX_COLUMNS = 30;

    public const MAX_FORMULA_LENGTH = 4000;

    protected $fillable = [
        'kind',
        'name',
        'target_key',
        'formula',
        'format',
        'position',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected $attributes = [
        'format'   => 'auto',
        'position' => 0,
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function scopeColumns(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_COLUMN)->orderBy('position')->orderBy('id');
    }

    public function scopeSummary(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_SUMMARY);
    }

    /** Key the frontend uses for a calculated column, also stored in hidden columns and widths. */
    public function columnKey(): string
    {
        return 'formula_' . $this->id;
    }

    public function toApiArray(): array
    {
        $updated_by = $this->relationLoaded('updatedBy') ? $this->updatedBy : null;

        return [
            'id'              => $this->id,
            'kind'            => $this->kind,
            'key'             => $this->kind === self::KIND_COLUMN ? $this->columnKey() : $this->target_key,
            'name'            => $this->name,
            'target_key'      => $this->target_key,
            'formula'         => $this->formula,
            'format'          => $this->format,
            'position'        => $this->position,
            'updated_by_name' => $updated_by ? trim($updated_by->first_name . ' ' . $updated_by->last_name) : null,
            'created_at'      => $this->created_at?->toIso8601String(),
            'updated_at'      => $this->updated_at?->toIso8601String(),
        ];
    }
}
