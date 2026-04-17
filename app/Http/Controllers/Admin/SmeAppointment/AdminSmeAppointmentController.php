<?php

namespace App\Http\Controllers\Admin\SmeAppointment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SmeAppointment\UpdateSmeAppointmentRequest;
use App\Http\Requests\Admin\SmeAppointment\UpdateSmeAppointmentStatusRequest;
use App\Http\Resources\SmeAppointmentResource;
use App\Models\SmeAppointment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminSmeAppointmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = SmeAppointment::with('user.organization');

        if ($search = $request->query('search')) {
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            })->orWhere('event_uri', 'like', "%{$search}%");
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($serviceType = $request->query('service_type')) {
            $query->where('service_type', $serviceType);
        }

        if ($dateFrom = $request->query('date_from')) {
            $query->whereDate('scheduled_at', '>=', $dateFrom);
        }

        if ($dateTo = $request->query('date_to')) {
            $query->whereDate('scheduled_at', '<=', $dateTo);
        }

        $sortField     = in_array($request->query('sort_field'), ['scheduled_at', 'created_at', 'service_type', 'status'])
            ? $request->query('sort_field')
            : 'created_at';
        $sortDirection = $request->query('sort_direction', 'desc') === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sortField, $sortDirection);

        $perPage  = (int) $request->query('per_page', 15);
        $paginated = $query->paginate($perPage);

        return response()->json([
            'data' => [
                'data'         => SmeAppointmentResource::collection($paginated->items()),
                'total'        => $paginated->total(),
                'last_page'    => $paginated->lastPage(),
                'current_page' => $paginated->currentPage(),
                'per_page'     => $paginated->perPage(),
            ],
        ]);
    }

    public function stats(): JsonResponse
    {
        $total = SmeAppointment::count();

        $byStatus = SmeAppointment::selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $byType = SmeAppointment::selectRaw('service_type, COUNT(*) as count')
            ->groupBy('service_type')
            ->pluck('count', 'service_type');

        return response()->json([
            'data' => [
                'total'         => $total,
                'pending'       => (int) ($byStatus['pending']   ?? 0),
                'confirmed'     => (int) ($byStatus['confirmed'] ?? 0),
                'cancelled'     => (int) ($byStatus['cancelled'] ?? 0),
                'completed'     => (int) ($byStatus['completed'] ?? 0),
                'authored'      => (int) ($byType['authored']      ?? 0),
                'collaboration' => (int) ($byType['collaboration'] ?? 0),
                'enhanced'      => (int) ($byType['enhanced']      ?? 0),
            ],
        ]);
    }

    public function show(int $appointment_id): JsonResponse
    {
        $appointment = SmeAppointment::with('user.organization')->find($appointment_id);

        if (! $appointment) {
            return response()->json(['message' => 'Appointment not found.'], 404);
        }

        return response()->json(['data' => new SmeAppointmentResource($appointment)]);
    }

    public function updateStatus(UpdateSmeAppointmentStatusRequest $request, int $appointment_id): JsonResponse
    {
        $appointment = SmeAppointment::with('user.organization')->find($appointment_id);

        if (! $appointment) {
            return response()->json(['message' => 'Appointment not found.'], 404);
        }

        $newStatus = $request->status;

        if (! $appointment->canTransitionTo($newStatus)) {
            return response()->json([
                'message' => 'Invalid status transition.',
                'errors'  => [
                    'status' => ["Cannot transition from '{$appointment->status}' to '{$newStatus}'."],
                ],
            ], 422);
        }

        $appointment->status = $newStatus;

        if ($request->filled('admin_notes')) {
            $appointment->admin_notes = $request->admin_notes;
        }

        $appointment->save();

        return response()->json(['data' => new SmeAppointmentResource($appointment)]);
    }

    public function update(UpdateSmeAppointmentRequest $request, int $appointment_id): JsonResponse
    {
        $appointment = SmeAppointment::with('user.organization')->find($appointment_id);

        if (! $appointment) {
            return response()->json(['message' => 'Appointment not found.'], 404);
        }

        if ($request->has('status')) {
            $newStatus = $request->status;

            if ($newStatus !== $appointment->status && ! $appointment->canTransitionTo($newStatus)) {
                return response()->json([
                    'message' => 'Invalid status transition.',
                    'errors'  => [
                        'status' => ["Cannot transition from '{$appointment->status}' to '{$newStatus}'."],
                    ],
                ], 422);
            }
        }

        $appointment->fill($request->only(['status', 'notes', 'admin_notes']));
        $appointment->save();

        return response()->json(['data' => new SmeAppointmentResource($appointment)]);
    }

    public function destroy(int $appointment_id): JsonResponse
    {
        $appointment = SmeAppointment::find($appointment_id);

        if (! $appointment) {
            return response()->json(['message' => 'Appointment not found.'], 404);
        }

        $appointment->delete();

        return response()->json(['message' => 'Appointment deleted successfully.']);
    }

    public function export(Request $request): StreamedResponse
    {
        $query = SmeAppointment::with('user.organization');

        if ($search = $request->query('search')) {
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            })->orWhere('event_uri', 'like', "%{$search}%");
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($serviceType = $request->query('service_type')) {
            $query->where('service_type', $serviceType);
        }

        if ($dateFrom = $request->query('date_from')) {
            $query->whereDate('scheduled_at', '>=', $dateFrom);
        }

        if ($dateTo = $request->query('date_to')) {
            $query->whereDate('scheduled_at', '<=', $dateTo);
        }

        $filename = 'sme-appointments-' . Carbon::now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'ID',
                'Client Name',
                'Email',
                'Organization',
                'Service Type',
                'Status',
                'Scheduled At',
                'Created At',
                'Admin Notes',
            ]);

            $query->orderBy('created_at', 'desc')->chunk(200, function ($appointments) use ($handle) {
                foreach ($appointments as $appointment) {
                    fputcsv($handle, [
                        $appointment->id,
                        trim(($appointment->user->first_name ?? '') . ' ' . ($appointment->user->last_name ?? '')),
                        $appointment->user->email ?? '',
                        $appointment->user->organization->name ?? '',
                        $appointment->service_type,
                        $appointment->status,
                        $appointment->scheduled_at?->toIso8601String() ?? '',
                        $appointment->created_at?->toIso8601String() ?? '',
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
