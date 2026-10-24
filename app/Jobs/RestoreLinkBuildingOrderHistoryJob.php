<?php

namespace App\Jobs;

use App\Models\LinkBuildingOrderPlacementRevision;
use App\Services\LinkBuildingOrderHistoryRestoreService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Restores a large action of the Link Building Orders edit history (an import
 * over thousands of rows) on the queue, where it cannot time out the request.
 * Progress and the final answer are kept in the cache under cacheKey(), and the
 * Version history panel polls them through LinkBuildingOrderHistoryController.
 */
class RestoreLinkBuildingOrderHistoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    /** How long the answer stays available to the panel. */
    private const STATUS_TTL_HOURS = 2;

    public function __construct(
        private readonly string $restore_id,
        private readonly string $batch_id,
        private readonly string $conflict_strategy,
        private readonly ?int $user_id,
        private readonly ?string $session_id,
    ) {}

    public static function cacheKey(string $restore_id): string
    {
        return "lbo_history_restore_{$restore_id}";
    }

    /** Status an admin may poll, set before the job is dispatched. */
    public static function markQueued(string $restore_id, int $user_id): void
    {
        Cache::put(self::cacheKey($restore_id), ['status' => 'queued', 'user_id' => $user_id], now()->addHours(self::STATUS_TTL_HOURS));
    }

    public function handle(LinkBuildingOrderHistoryRestoreService $restore_service): void
    {
        $this->saveStatus(['status' => 'processing']);

        $revisions = LinkBuildingOrderPlacementRevision::where('batch_id', $this->batch_id)->orderBy('id')->get();

        $result = $restore_service->restore(
            $revisions,
            $this->conflict_strategy,
            $this->session_id,
            $this->batch_id,
            $this->user_id,
        );

        $this->saveStatus(['status' => 'completed', 'result' => $restore_service->toPayload($result)]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Link building history restore failed.', [
            'restore_id' => $this->restore_id,
            'batch_id'   => $this->batch_id,
            'error'      => $exception->getMessage(),
        ]);

        $this->saveStatus(['status' => 'failed', 'message' => 'The restore could not be completed. Check the history before trying again.']);
    }

    private function saveStatus(array $status): void
    {
        Cache::put(
            self::cacheKey($this->restore_id),
            [...$status, 'user_id' => $this->user_id],
            now()->addHours(self::STATUS_TTL_HOURS)
        );
    }
}
