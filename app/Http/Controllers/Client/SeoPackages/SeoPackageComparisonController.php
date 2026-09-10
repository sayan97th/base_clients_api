<?php

namespace App\Http\Controllers\Client\SeoPackages;

use App\Http\Controllers\Controller;
use App\Models\SeoComparisonRow;
use App\Models\SeoPackage;
use Illuminate\Http\JsonResponse;

class SeoPackageComparisonController extends Controller
{
    public function index(): JsonResponse
    {
        $active_package_ids = SeoPackage::where('is_active', true)->pluck('id');

        $rows = SeoComparisonRow::with(['values' => function ($query) use ($active_package_ids) {
            $query->whereIn('seo_package_id', $active_package_ids);
        }])
            ->orderBy('sort_order')
            ->get();

        $data = $rows->map(fn (SeoComparisonRow $row) => [
            'id'     => $row->id,
            'label'  => $row->label,
            'values' => $row->values->mapWithKeys(fn ($value) => [$value->seo_package_id => $value->value]),
        ]);

        return response()->json(['data' => $data]);
    }
}
