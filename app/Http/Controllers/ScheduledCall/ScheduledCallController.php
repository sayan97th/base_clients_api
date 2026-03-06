<?php

namespace App\Http\Controllers\ScheduledCall;

use App\Http\Controllers\Controller;
use App\Http\Requests\ScheduledCall\StoreScheduledCallRequest;
use App\Http\Requests\ScheduledCall\UpdateScheduledCallRequest;
use App\Models\ScheduledCall;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScheduledCallController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ScheduledCall::query();

        if ($request->has('status') && in_array($request->status, ScheduledCall::STATUSES)) {
            $query->where('status', $request->status);
        }

        if ($request->has('call_type') && in_array($request->call_type, ScheduledCall::CALL_TYPES)) {
            $query->where('call_type', $request->call_type);
        }

        if ($request->has('contact_email')) {
            $query->where('contact_email', $request->contact_email);
        }

        $scheduled_calls = $query->orderBy('scheduled_date', 'asc')
            ->orderBy('scheduled_time', 'asc')
            ->paginate($request->get('per_page', 15));

        return response()->json($scheduled_calls);
    }

    public function store(StoreScheduledCallRequest $request): JsonResponse
    {
        $scheduled_call = ScheduledCall::create($request->validated());

        return response()->json([
            'message' => 'Scheduled call created successfully.',
            'scheduled_call' => $scheduled_call,
        ], 201);
    }

    public function show(ScheduledCall $scheduled_call): JsonResponse
    {
        return response()->json([
            'scheduled_call' => $scheduled_call,
        ]);
    }

    public function update(UpdateScheduledCallRequest $request, ScheduledCall $scheduled_call): JsonResponse
    {
        $scheduled_call->update($request->validated());

        return response()->json([
            'message' => 'Scheduled call updated successfully.',
            'scheduled_call' => $scheduled_call->fresh(),
        ]);
    }

    public function destroy(ScheduledCall $scheduled_call): JsonResponse
    {
        $scheduled_call->delete();

        return response()->json([
            'message' => 'Scheduled call deleted successfully.',
        ]);
    }
}
