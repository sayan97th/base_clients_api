<?php

namespace App\Http\Controllers\Admin\NewContent;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\NewContent\UpdateNewContentOrderStatusRequest;
use App\Models\NewContentOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminNewContentOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $per_page = min((int) $request->input('per_page', 25), 100);

        $query = NewContentOrder::with([
            'user:id,first_name,last_name,email',
            'items.tier:id,label',
            'items.intakeRows',
            'billing',
        ]);

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->whereHas('user', function ($u) use ($search) {
                    $u->where('first_name', 'like', "%{$search}%")
                      ->orWhere('last_name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                })->orWhereHas('items.intakeRows', function ($r) use ($search) {
                    $r->where('keyword_phrase', 'like', "%{$search}%");
                });
            });
        }

        if ($request->filled('client_id')) {
            $query->where('user_id', $request->input('client_id'));
        }

        if ($request->filled('tier_id')) {
            $query->whereHas('items', function ($q) use ($request) {
                $q->where('tier_id', $request->input('tier_id'));
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        $orders = $query->orderBy('created_at', 'desc')->paginate($per_page);

        $data = $orders->map(function (NewContentOrder $order) {
            return $this->formatOrderSummary($order);
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $orders->currentPage(),
                'per_page'     => $orders->perPage(),
                'total'        => $orders->total(),
                'last_page'    => $orders->lastPage(),
            ],
        ]);
    }

    public function show(string $order_id): JsonResponse
    {
        $order = NewContentOrder::with([
            'user:id,first_name,last_name,email',
            'items.tier:id,label',
            'items.intakeRows',
            'billing',
        ])->find($order_id);

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        return response()->json($this->formatOrderDetail($order));
    }

    public function updateStatus(UpdateNewContentOrderStatusRequest $request, string $order_id): JsonResponse
    {
        $order = NewContentOrder::find($order_id);

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $order->status = $request->input('status');

        if ($request->has('admin_notes')) {
            $order->admin_notes = $request->input('admin_notes');
        }

        $order->save();

        return response()->json([
            'order_id'   => $order->id,
            'status'     => $order->status,
            'updated_at' => $order->updated_at,
        ]);
    }

    private function formatOrderSummary(NewContentOrder $order): array
    {
        return [
            'order_id'    => $order->id,
            'order_title' => $order->order_title,
            'order_notes' => $order->order_notes,
            'admin_notes' => $order->admin_notes,
            'status'      => $order->status,
            'total_amount' => $order->total_amount,
            'created_at'  => $order->created_at,
            'client'      => $order->user ? [
                'client_id' => $order->user->id,
                'name'      => trim($order->user->first_name . ' ' . $order->user->last_name),
                'email'     => $order->user->email,
                'company'   => $order->billing?->company,
            ] : null,
            'new_content_items' => $order->items->map(function ($item) {
                return [
                    'item_id'           => $item->id,
                    'tier_id'           => $item->tier_id,
                    'tier_name'         => $item->tier?->label,
                    'quantity'          => $item->quantity,
                    'unit_price'        => $item->unit_price,
                    'intake_rows_count' => $item->intakeRows->count(),
                    'intake_rows'       => $item->intakeRows->map(fn ($row) => $this->formatRow($row))->values(),
                ];
            })->values(),
        ];
    }

    private function formatOrderDetail(NewContentOrder $order): array
    {
        return [
            'order_id'    => $order->id,
            'order_title' => $order->order_title,
            'order_notes' => $order->order_notes,
            'admin_notes' => $order->admin_notes,
            'status'      => $order->status,
            'total_amount' => $order->total_amount,
            'created_at'  => $order->created_at,
            'client'      => $order->user ? [
                'client_id' => $order->user->id,
                'name'      => trim($order->user->first_name . ' ' . $order->user->last_name),
                'email'     => $order->user->email,
                'company'   => $order->billing?->company,
            ] : null,
            'billing'     => $order->billing ? [
                'company'     => $order->billing->company,
                'address'     => $order->billing->address,
                'city'        => $order->billing->city,
                'state'       => $order->billing->state,
                'country'     => $order->billing->country,
                'postal_code' => $order->billing->postal_code,
            ] : null,
            'new_content_items' => $order->items->map(function ($item) {
                return [
                    'item_id'     => $item->id,
                    'tier_id'     => $item->tier_id,
                    'tier_name'   => $item->tier?->label,
                    'quantity'    => $item->quantity,
                    'unit_price'  => $item->unit_price,
                    'intake_rows' => $item->intakeRows->map(fn ($row) => $this->formatRow($row))->values(),
                ];
            })->values(),
        ];
    }

    private function formatRow(mixed $row): array
    {
        return [
            'row_id'          => $row->id,
            'row_index'       => $row->row_index,
            'keyword_phrase'  => $row->keyword_phrase,
            'type_of_content' => $row->type_of_content,
            'notes'           => $row->notes,
            'status'          => $row->status,
            'updated_at'      => $row->updated_at,
        ];
    }
}
