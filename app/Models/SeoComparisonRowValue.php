<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoComparisonRowValue extends Model
{
    use HasUuids;

    protected $fillable = [
        'seo_comparison_row_id',
        'seo_package_id',
        'value',
    ];

    public function row(): BelongsTo
    {
        return $this->belongsTo(SeoComparisonRow::class, 'seo_comparison_row_id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(SeoPackage::class, 'seo_package_id');
    }
}
