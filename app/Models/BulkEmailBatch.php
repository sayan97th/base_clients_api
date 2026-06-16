<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BulkEmailBatch extends Model
{
    protected $fillable = [
        'status',
        'send_mode',
        'total_count',
        'sent_count',
        'skipped_count',
        'failed_count',
        'is_stopped',
        'completed_at',
        'stopped_at',
    ];

    protected $casts = [
        'is_stopped'   => 'boolean',
        'completed_at' => 'datetime',
        'stopped_at'   => 'datetime',
    ];

    public function getProcessedCountAttribute(): int
    {
        return $this->sent_count + $this->skipped_count + $this->failed_count;
    }

    public function isComplete(): bool
    {
        return $this->total_count > 0 && $this->processed_count >= $this->total_count;
    }

    public function markCompleted(): void
    {
        $this->update([
            'status'       => 'completed',
            'completed_at' => now(),
        ]);
    }

    public function markStopped(): void
    {
        $this->update([
            'status'     => 'stopped',
            'is_stopped' => true,
            'stopped_at' => now(),
        ]);
    }
}
