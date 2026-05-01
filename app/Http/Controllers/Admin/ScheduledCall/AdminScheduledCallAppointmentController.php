<?php

namespace App\Http\Controllers\Admin\ScheduledCall;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ScheduledCallAppointment\UpdateScheduledCallAppointmentRequest;
use App\Http\Requests\Admin\ScheduledCallAppointment\UpdateScheduledCallAppointmentStatusRequest;
use App\Http\Resources\AdminScheduledCallAppointmentResource;
use App\Models\ScheduledCallAppointment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminScheduledCallAppointmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ScheduledCallAppointment::with('user.organization');

        if ($search = $request->query('search')) {
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($dateFrom = $request->query('date_from')) {
            $query->whereDate('scheduled_at', '>=', $dateFrom);
        }

        if ($dateTo = $request->query('date_to')) {
            $query->whereDate('scheduled_at', '<=', $dateTo);
        }

        $sortField = in_array($request->query('sort_field'), ['scheduled_at', 'created_at', 'status'])
            ? $request->query('sort_field')
            : 'scheduled_at';
        $sortDirection = $request->query('sort_direction', 'desc') === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sortField, $sortDirection);

        $perPage   = (int) $request->query('per_page', 15);
        $paginated = $query->paginate($perPage);

        return response()->json([
            'data' => [
                'data'         => AdminScheduledCallAppointmentResource::collection($paginated->items()),
                'total'        => $paginated->total(),
                'last_page'    => $paginated->lastPage(),
                'current_page' => $paginated->currentPage(),
                'per_page'     => $paginated->perPage(),
            ],
        ]);
    }

    public function stats(): JsonResponse
    {
        $total = ScheduledCallAppointment::count();

        $byStatus = ScheduledCallAppointment::selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        return response()->json([
            'data' => [
                'total'     => $total,
                'pending'   => (int) ($byStatus['pending']   ?? 0),
                'confirmed' => (int) ($byStatus['confirmed'] ?? 0),
                'cancelled' => (int) ($byStatus['cancelled'] ?? 0),
                'completed' => (int) ($byStatus['completed'] ?? 0),
                'no_show'   => (int) ($byStatus['no_show']   ?? 0),
            ],
        ]);
    }

    public function show(int $appointment_id): JsonResponse
    {
        $appointment = ScheduledCallAppointment::with('user.organization')->find($appointment_id);

        if (! $appointment) {
            return response()->json(['message' => 'Appointment not found.'], 404);
        }

        return response()->json(['data' => new AdminScheduledCallAppointmentResource($appointment)]);
    }

    public function updateStatus(UpdateScheduledCallAppointmentStatusRequest $request, int $appointment_id): JsonResponse
    {
        $appointment = ScheduledCallAppointment::with('user.organization')->find($appointment_id);

        if (! $appointment) {
            return response()->json(['message' => 'Appointment not found.'], 404);
        }

        $appointment->status = $request->status;

        if ($request->filled('admin_notes')) {
            $appointment->admin_notes = $request->admin_notes;
        }

        $appointment->save();

        return response()->json(['data' => new AdminScheduledCallAppointmentResource($appointment)]);
    }

    public function update(UpdateScheduledCallAppointmentRequest $request, int $appointment_id): JsonResponse
    {
        $appointment = ScheduledCallAppointment::with('user.organization')->find($appointment_id);

        if (! $appointment) {
            return response()->json(['message' => 'Appointment not found.'], 404);
        }

        $appointment->fill($request->only(['status', 'scheduled_at', 'notes', 'admin_notes']));
        $appointment->save();

        return response()->json(['data' => new AdminScheduledCallAppointmentResource($appointment)]);
    }

    public function destroy(int $appointment_id): JsonResponse
    {
        $appointment = ScheduledCallAppointment::find($appointment_id);

        if (! $appointment) {
            return response()->json(['message' => 'Appointment not found.'], 404);
        }

        $appointment->delete();

        return response()->json(null, 204);
    }

    public function export(Request $request): StreamedResponse
    {
        $query = ScheduledCallAppointment::with('user.organization');

        if ($search = $request->query('search')) {
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($dateFrom = $request->query('date_from')) {
            $query->whereDate('scheduled_at', '>=', $dateFrom);
        }

        if ($dateTo = $request->query('date_to')) {
            $query->whereDate('scheduled_at', '<=', $dateTo);
        }

        $filename = 'scheduled-calls-export-' . Carbon::now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'ID',
                'Client Name',
                'Email',
                'Organization',
                'Status',
                'Scheduled At',
                'Created At',
                'Notes',
                'Admin Notes',
            ]);

            $query->orderBy('scheduled_at', 'desc')->chunk(200, function ($appointments) use ($handle) {
                foreach ($appointments as $appointment) {
                    fputcsv($handle, [
                        $appointment->id,
                        trim(($appointment->user->first_name ?? '') . ' ' . ($appointment->user->last_name ?? '')),
                        $appointment->user->email ?? '',
                        $appointment->user->organization?->name ?? '',
                        $appointment->status,
                        $appointment->scheduled_at?->toIso8601String() ?? '',
                        $appointment->created_at?->toIso8601String() ?? '',
                        $appointment->notes ?? '',
                        $appointment->admin_notes ?? '',
                    ]);
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
