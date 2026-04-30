<?php

namespace App\Http\Controllers\Client\ScheduledCall;

use App\Http\Controllers\Controller;
use App\Http\Requests\ScheduledCallAppointment\CancelScheduledCallAppointmentRequest;
use App\Http\Requests\ScheduledCallAppointment\RescheduleScheduledCallAppointmentRequest;
use App\Http\Requests\ScheduledCallAppointment\StoreScheduledCallAppointmentRequest;
use App\Http\Resources\ScheduledCallAppointmentResource;
use App\Models\Notification;
use App\Models\ScheduledCallAppointment;
use App\Models\User;
use App\Services\CalendlyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppointmentController extends Controller
{
    public function __construct(private CalendlyService $calendly) {}

    public function store(StoreScheduledCallAppointmentRequest $request): JsonResponse
    {
        $scheduled_at = $this->calendly->resolveStartTime($request->event_uri) ?? now();

        $appointment = ScheduledCallAppointment::create([
            'user_id'      => $request->user()->id,
            'event_uri'    => $request->event_uri,
            'invitee_uri'  => $request->invitee_uri,
            'status'       => 'pending',
            'scheduled_at' => $scheduled_at,
            'notes'        => $request->notes,
        ]);

        return response()->json(['data' => new ScheduledCallAppointmentResource($appointment)], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $query = ScheduledCallAppointment::where('user_id', auth()->id());

        if ($request->filled('search')) {
            $query->where('notes', 'like', '%' . $request->search . '%');
        }

        if ($request->filled('status') && in_array($request->status, ScheduledCallAppointment::STATUSES)) {
            $query->where('status', $request->status);
        }

        if ($request->filled('date_from')) {
            $query->where('scheduled_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->where('scheduled_at', '<=', $request->date_to);
        }

        $allowed_sort_fields = ['scheduled_at', 'created_at', 'status'];
        $sort_field = in_array($request->sort_field, $allowed_sort_fields)
            ? $request->sort_field
            : 'scheduled_at';

        $sort_direction = $request->sort_direction === 'asc' ? 'asc' : 'desc';

        $per_page = min((int) ($request->per_page ?? 15), 100);

        $paginator = $query->orderBy($sort_field, $sort_direction)->paginate($per_page);

        return response()->json([
            'data' => [
                'data'         => ScheduledCallAppointmentResource::collection($paginator->items()),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
            ],
        ]);
    }

    public function stats(): JsonResponse
    {
        $counts = ScheduledCallAppointment::where('user_id', auth()->id())
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $total = array_sum($counts);

        return response()->json([
            'data' => [
                'total'     => $total,
                'pending'   => (int) ($counts['pending']   ?? 0),
                'confirmed' => (int) ($counts['confirmed'] ?? 0),
                'cancelled' => (int) ($counts['cancelled'] ?? 0),
                'completed' => (int) ($counts['completed'] ?? 0),
                'no_show'   => (int) ($counts['no_show']   ?? 0),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $appointment = ScheduledCallAppointment::find($id);

        if (! $appointment) {
            return response()->json(['message' => 'Appointment not found.'], 404);
        }

        if ($appointment->user_id !== auth()->id()) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json(['data' => new ScheduledCallAppointmentResource($appointment)]);
    }

    public function cancel(CancelScheduledCallAppointmentRequest $request, int $id): JsonResponse
    {
        $appointment = ScheduledCallAppointment::find($id);

        if (! $appointment) {
            return response()->json(['message' => 'Appointment not found.'], 404);
        }

        if ($appointment->user_id !== auth()->id()) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if (! $appointment->isCancellable()) {
            return response()->json([
                'message' => 'This appointment cannot be cancelled. Only pending or confirmed appointments can be cancelled.',
            ], 422);
        }

        $appointment->update([
            'status'              => 'cancelled',
            'cancellation_reason' => $request->reason,
        ]);

        if ($appointment->event_uri) {
            $this->calendly->cancelEvent($appointment->event_uri, $request->reason);
        }

        return response()->json(['data' => new ScheduledCallAppointmentResource($appointment->fresh())]);
    }

    public function rescheduleRequest(RescheduleScheduledCallAppointmentRequest $request, int $id): JsonResponse
    {
        $appointment = ScheduledCallAppointment::find($id);

        if (! $appointment) {
            return response()->json(['message' => 'Appointment not found.'], 404);
        }

        if ($appointment->user_id !== auth()->id()) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if (! $appointment->canRequestReschedule()) {
            return response()->json([
                'message' => 'A reschedule request cannot be submitted for this appointment.',
            ], 422);
        }

        $appointment->update([
            'reschedule_reason' => $request->reason,
            'preferred_dates'   => $request->preferred_dates,
        ]);

        $this->notifyAdmins($appointment, $request->user());

        return response()->json(['data' => new ScheduledCallAppointmentResource($appointment->fresh())]);
    }

    private function notifyAdmins(ScheduledCallAppointment $appointment, User $client): void
    {
        $admin_ids = User::whereHas('roles', function ($query) {
            $query->whereIn('name', ['super_admin', 'admin', 'staff']);
        })->pluck('id');

        foreach ($admin_ids as $admin_id) {
            Notification::create([
                'user_id'      => $admin_id,
                'type'         => 'reschedule_request',
                'message'      => "{$client->first_name} {$client->last_name} has requested to reschedule their scheduled call (appointment #{$appointment->id}).",
                'preview_text' => $appointment->reschedule_reason,
                'is_read'      => false,
                'is_archived'  => false,
                'is_snoozed'   => false,
            ]);
        }
    }
}
