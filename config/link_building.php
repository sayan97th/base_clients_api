<?php

/**
 * Settings of the Link Building Orders edit history ("Version history").
 */
return [

    /*
     * Days a change is kept before `php artisan model:prune` (scheduled daily)
     * removes it. One import over every row of the table writes one revision per
     * row, so this is what keeps the history from growing forever.
     */
    'history_retention_days' => (int) env('LINK_BUILDING_HISTORY_RETENTION_DAYS', 365),

    /*
     * Restores of an action with more revisions than this run on the queue
     * instead of inside the request, so a large import can be restored without
     * the request timing out. The panel polls until the job finishes.
     */
    'history_sync_restore_max_revisions' => (int) env('LINK_BUILDING_HISTORY_SYNC_RESTORE_MAX', 1000),

    /*
     * Up to this many restored rows are broadcast one by one and sent back to the
     * table. Above it, open tables get a single "reload" broadcast instead, which
     * is far cheaper than thousands of row events.
     */
    'history_restore_row_events_max' => (int) env('LINK_BUILDING_HISTORY_ROW_EVENTS_MAX', 200),

];
