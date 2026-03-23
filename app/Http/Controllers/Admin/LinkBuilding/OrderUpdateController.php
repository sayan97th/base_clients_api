<?php

namespace App\Http\Controllers\Admin\LinkBuilding;

use App\Http\Controllers\Controller;
use App\Http\Requests\LinkBuilding\StoreOrderUpdateRequest;
use App\Http\Requests\LinkBuilding\UpdateOrderStatusRequest;
use App\Mail\OrderStatusChangeMail;
use App\Mail\OrderUpdateMail;
use App\Models\LinkBuildingOrder;
use App\Models\LinkBuildingOrderUpdate;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;

class OrderUpdateController extends Controller
{
    public function index(string $order_id): JsonResponse
    {
        $order = LinkBuildingOrder::findOrFail($order_id);

        $updates = $order->updates()
            ->with('createdBy:id,first_name,last_name')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn (LinkBuildingOrderUpdate $update) => $this->formatUpdate($update));

        return response()->json(['data' => $updates]);
    }

    public function store(StoreOrderUpdateRequest $request, string $order_id): JsonResponse
    {
        $order = LinkBuildingOrder::with('user')->findOrFail($order_id);

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
            Mail::to($order->user->email)->queue(
                new OrderUpdateMail(
                    user: $order->user,
                    update_title: $update->title,
                    update_message: $update->message,
                    order_id: $order->id,
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

        if (!$update) {
            return response()->json(['message' => 'Order update not found.'], 404);
        }

        $update->delete();

        return response()->json(null, 204);
    }

    public function updateStatus(UpdateOrderStatusRequest $request, string $order_id): JsonResponse
    {
        $order = LinkBuildingOrder::with('user')->findOrFail($order_id);

        $new_status = $request->input('status');

        $order->update(['status' => $new_status]);

        // Record the status change as a timeline entry
        LinkBuildingOrderUpdate::create([
            'order_id'      => $order->id,
            'created_by_id' => auth()->id(),
            'title'         => 'Order status changed to ' . ucfirst($new_status),
            'message'       => $this->statusChangeMessage($new_status),
            'status_change' => $new_status,
            'send_email'    => false,
        ]);

        if ($request->boolean('notify_user') && $order->user) {
            Mail::to($order->user->email)->queue(
                new OrderStatusChangeMail(
                    user: $order->user,
                    new_status: $order->status,
                    order_id: $order->id,
                )
            );
        }

        return response()->json([
            'message' => 'Order status updated to ' . $new_status . '.',
            'status'  => $order->status,
        ]);
    }

    private function statusChangeMessage(string $status): string
    {
        return match ($status) {
            'completed'  => 'Your order has been completed. Thank you for your business!',
            'processing' => 'Great news — your order is now being actively processed.',
            'cancelled'  => 'Your order has been cancelled. Please contact support if you have questions.',
            'pending'    => 'Your order has been placed back in the pending queue.',
            default      => 'Your order status has been updated to ' . ucfirst($status) . '.',
        };
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
