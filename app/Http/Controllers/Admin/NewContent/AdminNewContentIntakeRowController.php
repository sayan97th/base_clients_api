<?php

namespace App\Http\Controllers\Admin\NewContent;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\NewContent\AddIntakeRowsRequest;
use App\Http\Requests\Admin\NewContent\UpdateIntakeRowRequest;
use App\Models\NewContentIntakeRow;
use App\Models\NewContentOrder;
use App\Models\NewContentOrderItem;
use Illuminate\Http\JsonResponse;

class AdminNewContentIntakeRowController extends Controller
{
    public function store(AddIntakeRowsRequest $request, string $order_id, string $item_id): JsonResponse
    {
        $order = NewContentOrder::find($order_id);

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $item = NewContentOrderItem::where('id', $item_id)
            ->where('order_id', $order_id)
            ->first();

        if (! $item) {
            return response()->json(['message' => 'Order item not found.'], 404);
        }

        $next_index = $item->intakeRows()->max('row_index') + 1;

        $created = [];

        foreach ($request->input('intake_rows') as $row_data) {
            $row = $item->intakeRows()->create([
                'row_index'       => $next_index++,
                'keyword_phrase'  => $row_data['keyword_phrase'],
                'type_of_content' => $row_data['type_of_content'],
                'notes'           => $row_data['notes'] ?? null,
                'status'          => 'pending',
            ]);

            $created[] = [
                'row_id'          => $row->id,
                'row_index'       => $row->row_index,
                'keyword_phrase'  => $row->keyword_phrase,
                'type_of_content' => $row->type_of_content,
                'notes'           => $row->notes,
                'status'          => $row->status,
                'created_at'      => $row->created_at,
            ];
        }

        return response()->json(['created' => $created], 201);
    }

    public function update(UpdateIntakeRowRequest $request, string $order_id, string $row_id): JsonResponse
    {
        $row = NewContentIntakeRow::whereHas('item', function ($q) use ($order_id) {
            $q->where('order_id', $order_id);
        })->find($row_id);

        if (! $row) {
            return response()->json(['message' => 'Intake row not found.'], 404);
        }

        $row->fill($request->validated());
        $row->save();

        return response()->json([
            'row_id'          => $row->id,
            'row_index'       => $row->row_index,
            'keyword_phrase'  => $row->keyword_phrase,
            'type_of_content' => $row->type_of_content,
            'notes'           => $row->notes,
            'status'          => $row->status,
            'updated_at'      => $row->updated_at,
        ]);
    }

    public function destroy(string $order_id, string $row_id): JsonResponse
    {
        $row = NewContentIntakeRow::whereHas('item', function ($q) use ($order_id) {
            $q->where('order_id', $order_id);
        })->find($row_id);

        if (! $row) {
            return response()->json(['message' => 'Intake row not found.'], 404);
        }

        $row->delete();

        return response()->json(['message' => 'Intake row deleted successfully.']);
    }
}
