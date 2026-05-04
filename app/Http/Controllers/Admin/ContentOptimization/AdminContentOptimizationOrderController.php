<?php

namespace App\Http\Controllers\Admin\ContentOptimization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ContentOptimization\UpdateContentOptimizationOrderStatusRequest;
use App\Models\ContentOptimizationOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminContentOptimizationOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $per_page = min((int) $request->input('per_page', 25), 100);

        $query = ContentOptimizationOrder::with([
            'user:id,first_name,last_name,email',
            'billing:order_id,company',
        ])->withCount([
            'items as items_count',
            'intakeRows as intake_rows_count',
        ]);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('client_id')) {
            $query->where('user_id', $request->input('client_id'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('order_title', 'like', "%{$search}%")
                  ->orWhere('order_notes', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($u) use ($search) {
                      $u->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }

        $allowed_sort = ['created_at', 'total_amount', 'status'];
        $sort_by      = in_array($request->input('sort_by'), $allowed_sort) ? $request->input('sort_by') : 'created_at';
        $sort_dir     = $request->input('sort_dir') === 'asc' ? 'asc' : 'desc';

        $orders = $query->orderBy($sort_by, $sort_dir)->paginate($per_page);

        $data = $orders->map(fn (ContentOptimizationOrder $order) => [
            'id'                => $order->id,
            'order_title'       => $order->order_title,
            'order_notes'       => $order->order_notes,
            'total_amount'      => $order->total_amount,
            'status'            => $order->status,
            'created_at'        => $order->created_at,
            'items_count'       => (int) $order->items_count,
            'intake_rows_count' => (int) $order->intake_rows_count,
            'client'            => $order->user ? [
                'id'    => $order->user->id,
                'name'  => trim($order->user->first_name . ' ' . $order->user->last_name),
                'email' => $order->user->email,
            ] : null,
        ])->values();

        return response()->json([
            'data'         => $data,
            'current_page' => $orders->currentPage(),
            'last_page'    => $orders->lastPage(),
            'total'        => $orders->total(),
            'per_page'     => $orders->perPage(),
        ]);
    }

    public function show(string $order_id): JsonResponse
    {
        $order = ContentOptimizationOrder::with([
            'user:id,first_name,last_name,email',
            'billing',
            'items.tier:id,label,word_count_range,turnaround_days,price',
            'items.intakeRows',
        ])->find($order_id);

        if (!$order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $user    = $order->user;
        $billing = $order->billing;

        return response()->json([
            'data' => [
                'id'           => $order->id,
                'order_title'  => $order->order_title,
                'order_notes'  => $order->order_notes,
                'total_amount' => $order->total_amount,
                'status'       => $order->status,
                'created_at'   => $order->created_at,
                'updated_at'   => $order->updated_at,
                'client'       => $user ? [
                    'id'      => $user->id,
                    'name'    => trim($user->first_name . ' ' . $user->last_name),
                    'email'   => $user->email,
                    'company' => $billing?->company,
                ] : null,
                'billing' => $billing ? [
                    'company'     => $billing->company,
                    'address'     => $billing->address,
                    'city'        => $billing->city,
                    'state'       => $billing->state,
                    'country'     => $billing->country,
                    'postal_code' => $billing->postal_code,
                ] : null,
                'items' => $order->items->map(fn ($item) => [
                    'id'          => $item->id,
                    'tier_id'     => $item->tier_id,
                    'quantity'    => $item->quantity,
                    'unit_price'  => $item->unit_price,
                    'subtotal'    => $item->subtotal,
                    'tier'        => $item->tier ? [
                        'id'               => $item->tier->id,
                        'label'            => $item->tier->label,
                        'word_count_range' => $item->tier->word_count_range,
                        'turnaround_days'  => $item->tier->turnaround_days,
                        'price'            => $item->tier->price,
                    ] : null,
                    'intake_rows' => $item->intakeRows->map(fn ($row) => [
                        'row_index'          => $row->row_index,
                        'primary_keyword'    => $row->primary_keyword,
                        'secondary_keywords' => $row->secondary_keywords,
                        'content_page_url'   => $row->content_page_url,
                    ])->values(),
                ])->values(),
            ],
        ]);
    }

    public function updateStatus(UpdateContentOptimizationOrderStatusRequest $request, string $order_id): JsonResponse
    {
        $order = ContentOptimizationOrder::find($order_id);

        if (!$order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $order->update(['status' => $request->input('status')]);

        return response()->json([
            'id'         => $order->id,
            'status'     => $order->status,
            'updated_at' => $order->updated_at,
        ]);
    }
}
