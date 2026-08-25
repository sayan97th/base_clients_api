<?php

namespace App\Http\Controllers\Admin\LinkBuilding;

use App\Http\Controllers\Controller;
use App\Http\Requests\LinkBuilding\StoreOrderUpdateRequest;
use App\Mail\OrderUpdateMail;
use Illuminate\Database\Eloquent\Model;
use App\Models\ContentBriefOrder;
use App\Models\ContentOptimizationOrder;
use App\Models\LinkBuildingOrder;
use App\Models\LinkBuildingOrderUpdate;
use App\Models\NewContentOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;

class OrderUpdateController extends Controller
{
    public function index(string $order_id): JsonResponse
    {
        $order = $this->findOrder($order_id);

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $updates = LinkBuildingOrderUpdate::where('order_id', $order->id)
            ->with('createdBy:id,first_name,last_name')
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(fn (LinkBuildingOrderUpdate $update) => $this->formatUpdate($update));

        return response()->json(['data' => $updates]);
    }

    public function store(StoreOrderUpdateRequest $request, string $order_id): JsonResponse
    {
        $order = $this->findOrder($order_id);

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $order->load('user');

        $update = LinkBuildingOrderUpdate::create([
            'order_id'      => $order->id,
            'created_by_id' => auth()->id(),
            'title'         => $request->input('title'),
            'message'       => $request->input('message'),
            'status_change' => $request->input('status_change'),
            'send_email'    => $request->boolean('send_email'),
        ]);

        if ($request->input('status_change') !== null) {
            $order->update(['status' => $request->input('status_change')]);
        }

        if ($request->boolean('send_email') && $order->user) {
            $link_count      = null;
            $dr_tier_summary = null;

            if ($order instanceof LinkBuildingOrder) {
                $order->loadMissing('items.drTier', 'items.placements');
                $link_count      = $order->items->flatMap->placements->count();
                $dr_tier_summary = $order->items->pluck('drTier.label')->filter()->unique()->implode(', ') ?: null;
            }

            Mail::to($order->user->email)->queue(
                new OrderUpdateMail(
                    user: $order->user,
                    update_title: $update->title,
                    update_message: $update->message,
                    order_id: $order->id,
                    order_title: $order->order_title,
                    purchased_at: $order->created_at,
                    link_count: $link_count,
                    dr_tier_summary: $dr_tier_summary,
                )
            );
        }

        $update->load('createdBy:id,first_name,last_name');

        return response()->json($this->formatUpdate($update), 201);
    }

    public function destroy(string $order_id, string $update_id): JsonResponse
    {
        $update = LinkBuildingOrderUpdate::where('id', $update_id)
            ->where('order_id', $order_id)
            ->first();

        if (! $update) {
            return response()->json(['message' => 'Order update not found.'], 404);
        }

        $update->delete();

        return response()->json(null, 204);
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function findOrder(string $order_id): ?Model
    {
        $models = [
            LinkBuildingOrder::class,
            NewContentOrder::class,
            ContentOptimizationOrder::class,
            ContentBriefOrder::class,
        ];

        foreach ($models as $model_class) {
            $order = $model_class::find($order_id);
            if ($order) {
                return $order;
            }
        }

        return null;
    }

    private function formatUpdate(LinkBuildingOrderUpdate $update): array
    {
        return [
            'id'            => $update->id,
            'order_id'      => $update->order_id,
            'title'         => $update->title,
            'message'       => $update->message,
            'status_change' => $update->status_change,
            'send_email'    => $update->send_email,
            'created_by'    => $update->createdBy ? [
                'id'         => $update->createdBy->id,
                'first_name' => $update->createdBy->first_name,
                'last_name'  => $update->createdBy->last_name,
            ] : null,
            'created_at'    => $update->created_at,
            'updated_at'    => $update->updated_at,
        ];
    }
}
