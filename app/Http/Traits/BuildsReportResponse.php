<?php

namespace App\Http\Traits;

use App\Models\OrderReport;
use App\Models\OrderReportRow;
use App\Models\OrderReportTable;
use Carbon\Carbon;

trait BuildsReportResponse
{
    private function buildReportResponse(OrderReport $report): array
    {
        $tables = ($report->relationLoaded('tables') ? $report->tables : $report->tables()->with('rows')->get())
            ->map(fn (OrderReportTable $table) => $this->buildTableResponse($table))
            ->values()
            ->all();

        return [
            'id'         => $report->id,
            'order_id'   => $report->order_id,
            'sent_at'    => $report->sent_at,
            'tables'     => $tables,
            'created_at' => $report->created_at,
            'updated_at' => $report->updated_at,
        ];
    }

    private function buildTableResponse(OrderReportTable $table): array
    {
        $rows = ($table->relationLoaded('rows') ? $table->rows : $table->rows()->get())
            ->map(fn (OrderReportRow $row) => $this->buildRowResponse($row))
            ->values()
            ->all();

        return [
            'id'          => $table->id,
            'title'       => $table->title,
            'description' => $table->description,
            'rows'        => $rows,
            'created_at'  => $table->created_at,
            'updated_at'  => $table->updated_at,
        ];
    }

    private function formatOrderNumber(?string $order_number): ?string
    {
        if (! $order_number) {
            return null;
        }

        $clean = strtoupper(str_replace('-', '', $order_number));

        return 'ORD-' . substr($clean, 0, 8);
    }

    private function buildRowResponse(OrderReportRow $row): array
    {
        return [
            'id'             => $row->id,
            'order_number'   => $this->formatOrderNumber($row->order_number),
            'link_type'      => $row->link_type,
            'keyword'        => $row->keyword,
            'landing_page'   => $row->landing_page,
            'exact_match'    => $row->exact_match,
            'request_date'   => $row->request_date ? Carbon::parse($row->request_date)->format('Y-m-d') : null,
            'status'         => $row->status,
            'live_link'      => $row->live_link,
            'live_link_date' => $row->live_link_date ? Carbon::parse($row->live_link_date)->format('Y-m-d') : null,
            'dr'             => $row->dr,
            'created_at'     => $row->created_at,
            'updated_at'     => $row->updated_at,
        ];
    }
}
