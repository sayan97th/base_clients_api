<?php

namespace App\Observers;

use App\Models\LinkBuildingOrderPlacement;
use App\Services\LinkBuildingOrderHistoryRecorder;

/**
 * Records every change made to a Link Building Orders row through Eloquent in
 * the table's edit history, whichever screen or endpoint made it.
 */
class LinkBuildingOrderPlacementObserver
{
    public function __construct(private readonly LinkBuildingOrderHistoryRecorder $history) {}

    public function created(LinkBuildingOrderPlacement $placement): void
    {
        $this->history->recordCreated($placement);
    }

    public function updated(LinkBuildingOrderPlacement $placement): void
    {
        $this->history->recordUpdated($placement);
    }

    public function deleted(LinkBuildingOrderPlacement $placement): void
    {
        $this->history->recordDeleted($placement);
    }
}
