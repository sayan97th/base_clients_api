<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailInterceptLog extends Model
{
    public const MAX_RETAINED_ROWS = 500;

    protected $fillable = [
        'mailable_class',
        'audience',
        'original_recipient_email',
        'subject',
        'copied_to_emails',
        'intercepted_at',
    ];

    protected function casts(): array
    {
        return [
            'copied_to_emails' => 'array',
            'intercepted_at'   => 'datetime',
        ];
    }

    /**
     * Keeps the log table bounded by deleting everything past the most recent
     * MAX_RETAINED_ROWS entries. Called probabilistically from the listener so
     * a busy mail queue doesn't run this delete on every single intercepted send.
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
