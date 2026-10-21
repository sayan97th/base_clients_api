<?php

namespace App\Http\Controllers\Admin\LinkBuilding;

use App\Events\LinkBuildingOrderSheetFormulasChanged;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LinkBuilding\StoreLinkBuildingOrderFormulaColumnRequest;
use App\Http\Requests\Admin\LinkBuilding\UpdateLinkBuildingOrderFormulaColumnRequest;
use App\Http\Requests\Admin\LinkBuilding\UpdateLinkBuildingOrderSummaryFormulaRequest;
use App\Models\LinkBuildingOrderSheetFormula;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Team wide formulas of the admin "Link Building Orders" spreadsheet: calculated
 * columns added to the right of the table and the cells of the totals row under
 * it. Every admin side user sees and edits the same set, like a shared sheet, and
 * each change is broadcast so the other open tables reload them.
 */
class LinkBuildingOrderSheetFormulaController extends Controller
{
    /**
     * GET /api/admin/link-building-orders/sheet-formulas
     */
    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->sheetFormulas()]);
    }

    /**
     * POST /api/admin/link-building-orders/sheet-formulas/columns
     *
     * Adds a calculated column after the existing ones, unless a position is given.
     */
    public function storeColumn(StoreLinkBuildingOrderFormulaColumnRequest $request): JsonResponse
    {
        if (LinkBuildingOrderSheetFormula::columns()->count() >= LinkBuildingOrderSheetFormula::MAX_COLUMNS) {
            return response()->json([
                'message' => 'The table can have up to ' . LinkBuildingOrderSheetFormula::MAX_COLUMNS
                    . ' formula columns. Delete one before adding a new column.',
            ], 422);
        }

        $user     = $request->user();
        $position = $request->validated('position')
            ?? ((int) LinkBuildingOrderSheetFormula::columns()->max('position') + 1);

        $column = LinkBuildingOrderSheetFormula::create([
            'kind'               => LinkBuildingOrderSheetFormula::KIND_COLUMN,
            'name'               => $request->validated('name'),
            'formula'            => $request->validated('formula'),
            'format'             => $request->validated('format', 'auto'),
            'position'           => $position,
            'created_by_user_id' => $user->id,
            'updated_by_user_id' => $user->id,
        ]);
        $column->load('updatedBy:id,first_name,last_name');

        $this->broadcastChange($request);

        return response()->json([
            'message' => 'Formula column added.',
            'data'    => $column->toApiArray(),
        ], 201);
    }

    /**
     * PUT /api/admin/link-building-orders/sheet-formulas/columns/{id}
     */
    public function updateColumn(UpdateLinkBuildingOrderFormulaColumnRequest $request, int $id): JsonResponse
    {
        $column = LinkBuildingOrderSheetFormula::where('kind', LinkBuildingOrderSheetFormula::KIND_COLUMN)->findOrFail($id);

        $column->fill($request->safe()->only(['name', 'formula', 'format', 'position']));
        $column->updated_by_user_id = $request->user()->id;
        $column->save();
        $column->load('updatedBy:id,first_name,last_name');

        $this->broadcastChange($request);

        return response()->json([
            'message' => 'Formula column updated.',
            'data'    => $column->toApiArray(),
        ]);
    }

    /**
     * DELETE /api/admin/link-building-orders/sheet-formulas/columns/{id}
     *
     * Also clears the totals cell that sat under the removed column.
     */
    public function destroyColumn(Request $request, int $id): JsonResponse
    {
        $column = LinkBuildingOrderSheetFormula::where('kind', LinkBuildingOrderSheetFormula::KIND_COLUMN)->findOrFail($id);

        LinkBuildingOrderSheetFormula::summary()->where('target_key', $column->columnKey())->delete();
        $column->delete();

        $this->broadcastChange($request);

        return response()->json(['message' => 'Formula column deleted.']);
    }

    /**
     * PUT /api/admin/link-building-orders/sheet-formulas/summary
     *
     * Sets the formula of the totals cell under a column, or clears it when the
     * formula is empty. Returns every formula, so the table can replace its copy.
     */
    public function updateSummary(UpdateLinkBuildingOrderSummaryFormulaRequest $request): JsonResponse
    {
        $user       = $request->user();
        $target_key = $request->validated('target_key');
        $formula    = $request->validated('formula');

        if ($formula === null) {
            LinkBuildingOrderSheetFormula::summary()->where('target_key', $target_key)->delete();
        } else {
            $summary = LinkBuildingOrderSheetFormula::firstOrNew([
                'kind'       => LinkBuildingOrderSheetFormula::KIND_SUMMARY,
                'target_key' => $target_key,
            ]);
            $summary->formula            = $formula;
            $summary->created_by_user_id ??= $user->id;
            $summary->updated_by_user_id = $user->id;
            $summary->save();
        }

        $this->broadcastChange($request);

        return response()->json([
            'message' => $formula === null ? 'Totals cell cleared.' : 'Totals cell saved.',
            'data'    => $this->sheetFormulas(),
        ]);
    }

    /** @return array{columns: array<int, array>, summary: array<int, array>} */
    private function sheetFormulas(): array
    {
        $columns = LinkBuildingOrderSheetFormula::columns()
            ->with('updatedBy:id,first_name,last_name')
            ->get()
            ->map(fn (LinkBuildingOrderSheetFormula $formula) => $formula->toApiArray())
            ->values();

        $summary = LinkBuildingOrderSheetFormula::summary()
            ->with('updatedBy:id,first_name,last_name')
            ->orderBy('target_key')
            ->get()
            ->map(fn (LinkBuildingOrderSheetFormula $formula) => $formula->toApiArray())
            ->values();

        return ['columns' => $columns->all(), 'summary' => $summary->all()];
    }

    private function broadcastChange(Request $request): void
    {
        broadcast(new LinkBuildingOrderSheetFormulasChanged($request->header('X-Session-ID')));
    }
}
