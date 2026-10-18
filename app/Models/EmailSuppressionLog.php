<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailSuppressionLog extends Model
{
    public const MAX_RETAINED_ROWS = 500;

    protected $fillable = [
        'mailable_class',
        'email_type',
        'recipient_email',
        'subject',
        'reason',
        'suppressed_at',
    ];

    protected function casts(): array
    {
        return [
            'suppressed_at' => 'datetime',
        ];
    }

    /**
     * Keeps the log table bounded by deleting everything past the most recent
     * MAX_RETAINED_ROWS entries, mirroring EmailInterceptLog::pruneOldEntries().
     */
    public static function pruneOldEntries(): void
    {
        $cutoff_id = static::query()
            ->orderByDesc('id')
            ->skip(static::MAX_RETAINED_ROWS)
            ->take(1)
            ->value('id');

        if ($cutoff_id !== null) {
            static::where('id', '<=', $cutoff_id)->delete();
        }
    }
}
