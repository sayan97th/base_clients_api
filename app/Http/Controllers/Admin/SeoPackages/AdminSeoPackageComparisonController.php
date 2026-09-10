<?php

namespace App\Http\Controllers\Admin\SeoPackages;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SeoPackages\UpdateSeoPackageComparisonRequest;
use App\Models\SeoComparisonRow;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminSeoPackageComparisonController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = SeoComparisonRow::with('values')
            ->orderBy('sort_order')
            ->get();

        return response()->json(['data' => $rows->map(fn (SeoComparisonRow $row) => $this->formatRow($row))]);
    }

    public function update(UpdateSeoPackageComparisonRequest $request): JsonResponse
    {
        $rows = $request->validated()['rows'];

        $updated_rows = DB::transaction(function () use ($rows) {
            $kept_row_ids = [];

            foreach ($rows as $row_input) {
                $row = SeoComparisonRow::updateOrCreate(
                    ['id' => $row_input['id'] ?? (string) Str::uuid()],
                    [
                        'label'      => $row_input['label'],
                        'sort_order' => $row_input['sort_order'],
                    ]
                );

                $kept_row_ids[] = $row->id;

                $kept_package_ids = [];
                foreach ($row_input['values'] as $package_id => $value) {
                    $row->values()->updateOrCreate(
                        ['seo_package_id' => $package_id],
                        ['value' => $value]
                    );
                    $kept_package_ids[] = $package_id;
                }
                $row->values()->whereNotIn('seo_package_id', $kept_package_ids)->delete();
            }

            SeoComparisonRow::whereNotIn('id', $kept_row_ids)->delete();

            return SeoComparisonRow::with('values')->orderBy('sort_order')->get();
        });

        return response()->json(['data' => $updated_rows->map(fn (SeoComparisonRow $row) => $this->formatRow($row))]);
    }

    private function formatRow(SeoComparisonRow $row): array
    {
        return [
            'id'         => $row->id,
            'label'      => $row->label,
            'sort_order' => $row->sort_order,
            'values'     => $row->values->mapWithKeys(fn ($value) => [$value->seo_package_id => $value->value]),
        ];
    }
}
