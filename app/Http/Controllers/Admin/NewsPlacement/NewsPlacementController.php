<?php

namespace App\Http\Controllers\Admin\NewsPlacement;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\NewsPlacement\StoreNewsPlacementRequest;
use App\Http\Requests\Admin\NewsPlacement\UpdateNewsPlacementRequest;
use App\Models\NewsPlacement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

class NewsPlacementController extends Controller
{
    /** Columns allowed for sorting. */
    private const SORTABLE_FIELDS = [
        'domain',
        'dr',
        'traffic',
        'category',
        'price',
        'types_of_content',
        'do_follow_no_follow',
        'indexable',
        'well_known_site',
        'links_allowed',
        'price_1',
        'poc_1',
        'price_2',
        'poc_2',
        'tier',
        'pbn_check',
        'used_domain',
        'within_budget',
        'ref_domains',
        'created_at',
        'updated_at',
    ];

    /**
     * GET /api/admin/news-placements
     *
     * Returns a paginated, searchable, sortable list of news placements.
     */
    public function index(Request $request): JsonResponse
    {
        $search         = $request->input('search');
        $tier           = $request->input('tier');
        $sort_field     = $request->input('sort_field', 'domain');
        $sort_direction = $request->input('sort_direction', 'asc');
        $per_page       = min((int) $request->input('per_page', 50), 200);

        if (! in_array($sort_field, self::SORTABLE_FIELDS, true)) {
            $sort_field = 'domain';
        }

        if (! in_array($sort_direction, ['asc', 'desc'], true)) {
            $sort_direction = 'asc';
        }

        $query = NewsPlacement::query();

        if (filled($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('domain', 'like', '%' . $search . '%')
                    ->orWhere('category', 'like', '%' . $search . '%')
                    ->orWhere('types_of_content', 'like', '%' . $search . '%')
                    ->orWhere('poc_1', 'like', '%' . $search . '%')
                    ->orWhere('tier', 'like', '%' . $search . '%')
                    ->orWhere('additional_notes', 'like', '%' . $search . '%');
            });
        }

        if (filled($tier)) {
            $query->where('tier', $tier);
        }

        $query->orderBy($sort_field, $sort_direction);

        $paginated = $query->paginate($per_page);

        $data = $paginated->getCollection()
            ->map(fn (NewsPlacement $placement) => $this->formatRow($placement))
            ->values();

        return response()->json([
            'data'         => $data,
            'current_page' => $paginated->currentPage(),
            'last_page'    => $paginated->lastPage(),
            'per_page'     => $paginated->perPage(),
            'total'        => $paginated->total(),
            'from'         => $paginated->firstItem(),
            'to'           => $paginated->lastItem(),
        ]);
    }

    /**
     * POST /api/admin/news-placements
     *
     * Creates a new news placement row.
     */
    public function store(StoreNewsPlacementRequest $request): JsonResponse
    {
        $placement = NewsPlacement::create($request->validated());

        return response()->json([
            'message' => 'News placement created successfully.',
            'data'    => $this->formatRow($placement),
        ], 201);
    }

    /**
     * PUT /api/admin/news-placements/{id}
     *
     * Fully replaces all fields of an existing news placement.
     */
    public function update(UpdateNewsPlacementRequest $request, string $id): JsonResponse
    {
        $placement = NewsPlacement::find($id);

        if (! $placement) {
            return response()->json(['message' => 'News placement not found.'], 404);
        }

        $placement->update($request->validated());

        return response()->json([
            'message' => 'News placement updated successfully.',
            'data'    => $this->formatRow($placement->fresh()),
        ]);
    }

    /**
     * DELETE /api/admin/news-placements/{id}
     *
     * Permanently removes a news placement row.
     */
    public function destroy(string $id): JsonResponse
    {
        $placement = NewsPlacement::find($id);

        if (! $placement) {
            return response()->json(['message' => 'News placement not found.'], 404);
        }

        $placement->delete();

        return response()->json(['message' => 'News placement deleted successfully.']);
    }

    /**
     * GET /api/admin/news-placements/export
     *
     * Streams a CSV download of all matching news placements.
     * Authentication is validated via the ?token= query parameter so that
     * the browser can open this endpoint directly (e.g. via window.open).
     */
    public function export(Request $request): Response
    {
        $token = $request->query('token');

        if (! $token) {
            abort(401, 'Unauthenticated.');
        }

        try {
            JWTAuth::setToken($token)->authenticate();
        } catch (\Exception) {
            abort(401, 'Unauthenticated.');
        }

        $search = $request->input('search');
        $tier   = $request->input('tier');

        $query = NewsPlacement::query();

        if (filled($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('domain', 'like', '%' . $search . '%')
                    ->orWhere('category', 'like', '%' . $search . '%')
                    ->orWhere('types_of_content', 'like', '%' . $search . '%')
                    ->orWhere('poc_1', 'like', '%' . $search . '%')
                    ->orWhere('tier', 'like', '%' . $search . '%')
                    ->orWhere('additional_notes', 'like', '%' . $search . '%');
            });
        }

        if (filled($tier)) {
            $query->where('tier', $tier);
        }

        $query->orderBy('domain', 'asc');

        $headers = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="news-placements-export.csv"',
        ];

        $columns = [
            'domain', 'dr', 'traffic', 'category', 'price', 'types_of_content',
            'do_follow_no_follow', 'indexable', 'well_known_site', 'links_allowed',
            'additional_notes', 'price_1', 'poc_1', 'price_2', 'poc_2',
            'tier', 'pbn_check', 'used_domain', 'within_budget', 'ref_domains',
        ];

        $callback = function () use ($query, $columns) {
            $handle = fopen('php://output', 'w');

            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM
            fputcsv($handle, $columns);

            $query->chunk(500, function ($placements) use ($handle) {
                foreach ($placements as $placement) {
                    $row = $this->formatRow($placement);
                    fputcsv($handle, [
                        $row['domain'],
                        $row['dr'],
                        $row['traffic'],
                        $row['category'],
                        $row['price'],
                        $row['types_of_content'],
                        $row['do_follow_no_follow'],
                        $row['indexable'],
                        $row['well_known_site'],
                        $row['links_allowed'],
                        $row['additional_notes'],
                        $row['price_1'],
                        $row['poc_1'],
                        $row['price_2'],
                        $row['poc_2'],
                        $row['tier'],
                        $row['pbn_check'],
                        $row['used_domain'],
                        $row['within_budget'],
                        $row['ref_domains'],
                    ]);
                }
            });

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Formats a NewsPlacement model into the API response array.
     */
    private function formatRow(NewsPlacement $placement): array
    {
        return [
            'id'                  => $placement->id,
            'domain'              => $placement->domain ?? '',
            'dr'                  => $placement->dr ?? '',
            'traffic'             => $placement->traffic ?? '',
            'category'            => $placement->category ?? '',
            'price'               => $placement->price ?? '',
            'types_of_content'    => $placement->types_of_content ?? '',
            'do_follow_no_follow' => $placement->do_follow_no_follow ?? '',
            'indexable'           => $placement->indexable ?? '',
            'well_known_site'     => $placement->well_known_site ?? '',
            'links_allowed'       => $placement->links_allowed ?? '',
            'additional_notes'    => $placement->additional_notes ?? '',
            'price_1'             => $placement->price_1 ?? '',
            'poc_1'               => $placement->poc_1 ?? '',
            'price_2'             => $placement->price_2 ?? '',
            'poc_2'               => $placement->poc_2 ?? '',
            'tier'                => $placement->tier ?? '',
            'pbn_check'           => $placement->pbn_check ?? '',
            'used_domain'         => $placement->used_domain ?? '',
            'within_budget'       => $placement->within_budget ?? '',
            'ref_domains'         => $placement->ref_domains ?? '',
            'created_at'          => $placement->created_at?->toIso8601String(),
            'updated_at'          => $placement->updated_at?->toIso8601String(),
        ];
    }
}
