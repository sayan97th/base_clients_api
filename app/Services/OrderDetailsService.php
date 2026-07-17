<?php

namespace App\Services;

use App\Models\ContentBriefOrder;
use App\Models\ContentOptimizationOrder;
use App\Models\LinkBuildingOrder;
use App\Models\NewContentOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Centralises the "deferred intake details" workflow shared by Link Building,
 * New Content, Content Optimization and Content Brief orders.
 *
 * A paid order whose intake details (keywords / target URLs / content brief)
 * are not yet complete is parked in the `pending_details` status so it stays
 * visible on the dashboards instead of silently entering the work queue with
 * empty data. Once the details are submitted the order transitions to
 * `new_request` and — for Link Building only — the turnaround clock starts.
 */
class OrderDetailsService
{
    /** Default turnaround window (in days) applied to Link Building placements. */
    public const LINK_BUILDING_TURNAROUND_DAYS = 30;

    // ── Completeness ─────────────────────────────────────────────────────────

    /**
     * Whether the given order has all the intake details required to begin work.
     */
    public function isComplete(Model $order): bool
    {
        return match (true) {
            $order instanceof LinkBuildingOrder        => $this->isLinkBuildingComplete($order),
            $order instanceof NewContentOrder          => $this->isNewContentComplete($order),
            $order instanceof ContentOptimizationOrder => $this->isContentOptimizationComplete($order),
            $order instanceof ContentBriefOrder        => $this->isContentBriefComplete($order),
            default                                    => true,
        };
    }

    public function isLinkBuildingComplete(LinkBuildingOrder $order): bool
    {
        $order->loadMissing('items.placements');

        $placements = $order->items->flatMap->placements;

        if ($placements->isEmpty()) {
            return false;
        }

        return $placements->every(
            fn ($placement) => filled($placement->keyword) && filled($placement->landing_page)
        );
    }

    public function isNewContentComplete(NewContentOrder $order): bool
    {
        $order->loadMissing('items.intakeRows');

        if ($order->items->isEmpty()) {
            return false;
        }

        return $order->items->every(function ($item) {
            if ($item->intakeRows->isEmpty()) {
                return false;
            }

            return $item->intakeRows->every(
                fn ($row) => filled($row->keyword_phrase) && filled($row->type_of_content)
            );
        });
    }

    public function isContentOptimizationComplete(ContentOptimizationOrder $order): bool
    {
        $order->loadMissing('items.intakeRows');

        return $this->hasCompleteKeywordUrlRows($order);
    }

    public function isContentBriefComplete(ContentBriefOrder $order): bool
    {
        $order->loadMissing('items.intakeRows');

        return $this->hasCompleteKeywordUrlRows($order);
    }

    /**
     * Shared completeness rule for the primary-keyword + content-page-url intake
     * shape used by both Content Optimization and Content Brief orders.
     */
    private function hasCompleteKeywordUrlRows(Model $order): bool
    {
        if ($order->items->isEmpty()) {
            return false;
        }

        return $order->items->every(function ($item) {
            if ($item->intakeRows->isEmpty()) {
                return false;
            }

            return $item->intakeRows->every(
                fn ($row) => filled($row->primary_keyword) && filled($row->content_page_url)
            );
        });
    }

    // ── Status resolution ──────────────────────────────────────────────────────

    /**
     * The working status a *paid* order should carry based on its intake details:
     * `new_request` when the details are complete, otherwise `pending_details`.
     */
    public function resolvePaidStatus(Model $order): string
    {
        return $this->isComplete($order) ? 'new_request' : 'pending_details';
    }

    /**
     * Persist the paid working status on the order and, for a complete Link
     * Building order, start the turnaround clock. Safe to call inside the
     * checkout transaction or after a deferred invoice is paid.
     *
     * When $force_pending is true (the client chose "Skip for now" at checkout),
     * the order is parked in `pending_details` regardless of how much intake data
     * was entered, and the turnaround clock is NOT started — the client wants to
     * review/complete the details later. Any data they did enter is still saved.
     */
    public function applyPaidStatus(Model $order, bool $force_pending = false): void
    {
        $status = $force_pending ? 'pending_details' : $this->resolvePaidStatus($order);

        if ($order->status !== $status) {
            $order->status = $status;
            $order->save();
        }

        if (! $force_pending && $order instanceof LinkBuildingOrder && $status === 'new_request') {
            $this->startLinkBuildingClock($order);
        }
    }

    // ── Submitting deferred details ──────────────────────────────────────────

    /**
     * Update the keyword / target URL of a Link Building order's placements from
     * the fill-in-later form. Placements are matched by id and updated in place
     * so their BL- identifiers and any admin fulfillment data are preserved.
     *
     * @param  array<int, array<string, mixed>>  $placements  [{id, keyword, landing_page, exact_match}]
     */
    public function submitLinkBuildingDetails(LinkBuildingOrder $order, array $placements): void
    {
        DB::transaction(function () use ($order, $placements) {
            $order->loadMissing('items.placements');
            $by_id = $order->items->flatMap->placements->keyBy('id');

            foreach ($placements as $row) {
                $placement = $by_id->get($row['id'] ?? null);

                if (! $placement) {
                    continue;
                }

                $placement->keyword      = ($row['keyword'] ?? null) ?: null;
                $placement->landing_page = ($row['landing_page'] ?? null) ?: null;
                $placement->exact_match  = (bool) ($row['exact_match'] ?? false);
                $placement->save();
            }

            $order->refresh();
            $this->finalizeAfterDetails($order);
        });
    }

    /**
     * Replace the intake rows of a New Content order from the fill-in-later form.
     *
     * @param  array<int, array<string, mixed>>  $items  [{item_id, intake_rows: [...]}]
     */
    public function submitNewContentDetails(NewContentOrder $order, array $items): void
    {
        DB::transaction(function () use ($order, $items) {
            $order->loadMissing('items.intakeRows');
            $by_item = $order->items->keyBy('id');

            foreach ($items as $entry) {
                $item = $by_item->get($entry['item_id'] ?? null);

                if (! $item) {
                    continue;
                }

                $item->intakeRows()->delete();

                foreach (array_values($entry['intake_rows'] ?? []) as $index => $row) {
                    $item->intakeRows()->create([
                        'row_index'          => $index + 1,
                        'keyword_phrase'     => ($row['keyword_phrase'] ?? null) ?: null,
                        'secondary_keywords' => ($row['secondary_keywords'] ?? null) ?: null,
                        'type_of_content'    => ($row['type_of_content'] ?? null) ?: null,
                        'notes'              => ($row['notes'] ?? null) ?: null,
                        'status'             => 'pending',
                    ]);
                }
            }

            $order->refresh();
            $this->finalizeAfterDetails($order);
        });
    }

    /**
     * Replace the intake rows of a Content Optimization order.
     *
     * @param  array<int, array<string, mixed>>  $items  [{item_id, intake_rows: [...]}]
     */
    public function submitContentOptimizationDetails(ContentOptimizationOrder $order, array $items): void
    {
        DB::transaction(function () use ($order, $items) {
            $this->replaceKeywordUrlRows($order, $items);
            $order->refresh();
            $this->finalizeAfterDetails($order);
        });
    }

    /**
     * Replace the intake rows of a Content Brief order.
     *
     * @param  array<int, array<string, mixed>>  $items  [{item_id, intake_rows: [...]}]
     */
    public function submitContentBriefDetails(ContentBriefOrder $order, array $items): void
    {
        DB::transaction(function () use ($order, $items) {
            $this->replaceKeywordUrlRows($order, $items);
            $order->refresh();
            $this->finalizeAfterDetails($order);
        });
    }

    /**
     * Shared row writer for the primary-keyword + content-page-url intake shape
     * (Content Optimization and Content Brief).
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function replaceKeywordUrlRows(Model $order, array $items): void
    {
        $order->loadMissing('items.intakeRows');
        $by_item = $order->items->keyBy('id');

        foreach ($items as $entry) {
            $item = $by_item->get($entry['item_id'] ?? null);

            if (! $item) {
                continue;
            }

            $item->intakeRows()->delete();

            foreach (array_values($entry['intake_rows'] ?? []) as $index => $row) {
                $item->intakeRows()->create([
                    'row_index'          => $index + 1,
                    'primary_keyword'    => ($row['primary_keyword'] ?? null) ?: null,
                    'secondary_keywords' => ($row['secondary_keywords'] ?? null) ?: null,
                    'content_page_url'   => ($row['content_page_url'] ?? null) ?: null,
                    'notes'              => ($row['notes'] ?? null) ?: null,
                ]);
            }
        }
    }

    /**
     * After details are written, re-resolve an order that is still awaiting its
     * details (or freshly paid). Orders already in progress keep their status —
     * only the underlying data is updated.
     */
    private function finalizeAfterDetails(Model $order): void
    {
        if (in_array($order->status, ['pending_details', 'new_request'], true)) {
            $this->applyPaidStatus($order);
        }
    }

    // ── Turnaround clock (Link Building only) ────────────────────────────────

    /**
     * Starts (or restarts) the turnaround clock on every placement of a Link
     * Building order. The clock deliberately starts here — when the details are
     * confirmed — rather than at purchase, so deferred orders are not penalised
     * for time spent waiting on the client's keywords/target URLs.
     */
    public function startLinkBuildingClock(LinkBuildingOrder $order, int $days = self::LINK_BUILDING_TURNAROUND_DAYS): void
    {
        $order->loadMissing('items.placements');

        $request_date  = now()->format('m/d/Y');
        $delivery_date = now()->addDays($days)->format('m/d/Y');

        foreach ($order->items as $item) {
            foreach ($item->placements as $placement) {
                // Only start the clock on placements that have not already been
                // scheduled, so re-submitting details never resets an in-flight
                // delivery estimate an admin may have adjusted.
                if (filled($placement->estimated_delivery_date)) {
                    continue;
                }

                $placement->update([
                    'request_date'              => $request_date,
                    'estimated_delivery_date'   => $delivery_date,
                    'estimated_turnaround_days' => (string) $days,
                    'status'                    => $placement->status === 'Pending Details'
                        ? 'New Request'
                        : $placement->status,
                ]);
            }
        }
    }
}
