<?php

namespace App\Http\Controllers\Admin\SeoPackages;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SeoPackages\UpdateSeoPackageAppointmentRequest;
use App\Http\Requests\Admin\SeoPackages\UpdateSeoPackageAppointmentStatusRequest;
use App\Http\Resources\AdminSeoPackageAppointmentResource;
use App\Models\SeoPackageAppointment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminSeoPackageAppointmentController extends Controller
{
    private array $eagerLoad = [
        'user:id,first_name,last_name,email,organization_id',
        'user.organization:id,name',
        'package:id,name,slug,price_per_month',
    ];

    public function index(Request $request): JsonResponse
    {
        $query = SeoPackageAppointment::with($this->eagerLoad);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('user', function ($user_q) use ($search) {
                    $user_q->where('first_name', 'like', "%{$search}%")
                           ->orWhere('last_name', 'like', "%{$search}%")
                           ->orWhere('email', 'like', "%{$search}%");
                })->orWhereHas('package', function ($pkg_q) use ($search) {
                    $pkg_q->where('name', 'like', "%{$search}%");
                });
            });
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($date_from = $request->query('date_from')) {
            $query->whereDate('scheduled_at', '>=', $date_from);
        }

        if ($date_to = $request->query('date_to')) {
            $query->whereDate('scheduled_at', '<=', $date_to);
        }

        $allowed_sort_fields = ['scheduled_at', 'created_at', 'status'];
        $sort_field          = $request->query('sort_field', 'scheduled_at');
        $sort_direction      = $request->query('sort_direction', 'desc') === 'asc' ? 'asc' : 'desc';

        if ($sort_field === 'package_name') {
            $query->leftJoin('seo_packages', 'seo_package_appointments.seo_package_id', '=', 'seo_packages.id')
                  ->select('seo_package_appointments.*')
                  ->orderBy('seo_packages.name', $sort_direction);
        } elseif (in_array($sort_field, $allowed_sort_fields)) {
            $query->orderBy("seo_package_appointments.{$sort_field}", $sort_direction);
        } else {
            $query->orderBy('seo_package_appointments.scheduled_at', $sort_direction);
        }

        $per_page  = min((int) $request->query('per_page', 15), 100);
        $paginated = $query->paginate($per_page);

        return response()->json([
            'data' => [
                'data'         => AdminSeoPackageAppointmentResource::collection($paginated->items()),
                'total'        => $paginated->total(),
                'last_page'    => $paginated->lastPage(),
                'current_page' => $paginated->currentPage(),
                'per_page'     => $paginated->perPage(),
            ],
        ]);
    }

    public function stats(): JsonResponse
    {
        $total     = SeoPackageAppointment::count();
        $by_status = SeoPackageAppointment::selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        return response()->json([
            'data' => [
                'total'     => $total,
                'pending'   => (int) ($by_status['pending']   ?? 0),
                'confirmed' => (int) ($by_status['confirmed'] ?? 0),
                'cancelled' => (int) ($by_status['cancelled'] ?? 0),
                'completed' => (int) ($by_status['completed'] ?? 0),
            ],
        ]);
    }

    public function show(int $appointment_id): JsonResponse
    {
        $appointment = SeoPackageAppointment::with($this->eagerLoad)->find($appointment_id);

        if (! $appointment) {
            return response()->json(['message' => 'Appointment not found.'], 404);
        }

        return response()->json(['data' => new AdminSeoPackageAppointmentResource($appointment)]);
    }

    public function updateStatus(UpdateSeoPackageAppointmentStatusRequest $request, int $appointment_id): JsonResponse
    {
        $appointment = SeoPackageAppointment::with($this->eagerLoad)->find($appointment_id);

        if (! $appointment) {
            return response()->json(['message' => 'Appointment not found.'], 404);
        }

        $appointment->status = $request->status;

        if ($request->filled('admin_notes')) {
            $appointment->admin_notes = $request->admin_notes;
        }

        $appointment->save();

        return response()->json(['data' => new AdminSeoPackageAppointmentResource($appointment)]);
    }

    public function update(UpdateSeoPackageAppointmentRequest $request, int $appointment_id): JsonResponse
    {
        $appointment = SeoPackageAppointment::with($this->eagerLoad)->find($appointment_id);

        if (! $appointment) {
            return response()->json(['message' => 'Appointment not found.'], 404);
        }

        $appointment->fill($request->only(['status', 'scheduled_at', 'admin_notes', 'notes']));
        $appointment->save();

        return response()->json(['data' => new AdminSeoPackageAppointmentResource($appointment)]);
    }

    public function destroy(int $appointment_id): Response|JsonResponse
    {
        $appointment = SeoPackageAppointment::find($appointment_id);

        if (! $appointment) {
            return response()->json(['message' => 'Appointment not found.'], 404);
        }

        $appointment->delete();

        return response()->noContent();
    }

    public function export(Request $request): StreamedResponse
    {
        $query = SeoPackageAppointment::with([
            'user:id,first_name,last_name,email,organization_id',
            'user.organization:id,name',
            'package:id,name,price_per_month',
        ]);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('user', function ($user_q) use ($search) {
                    $user_q->where('first_name', 'like', "%{$search}%")
                           ->orWhere('last_name', 'like', "%{$search}%")
                           ->orWhere('email', 'like', "%{$search}%");
                })->orWhereHas('package', function ($pkg_q) use ($search) {
                    $pkg_q->where('name', 'like', "%{$search}%");
                });
            });
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($date_from = $request->query('date_from')) {
            $query->whereDate('scheduled_at', '>=', $date_from);
        }

        if ($date_to = $request->query('date_to')) {
            $query->whereDate('scheduled_at', '<=', $date_to);
        }

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'id',
                'client_name',
                'client_email',
                'organization',
                'package_name',
                'price_per_month',
                'status',
                'scheduled_at',
                'notes',
                'admin_notes',
                'created_at',
            ]);

            $query->orderBy('created_at', 'desc')->chunk(200, function ($appointments) use ($handle) {
                foreach ($appointments as $appointment) {
                    fputcsv($handle, [
                        $appointment->id,
                        trim(($appointment->user->first_name ?? '') . ' ' . ($appointment->user->last_name ?? '')),
                        $appointment->user->email ?? '',
                        $appointment->user->organization?->name ?? '',
                        $appointment->package->name ?? '',
                        $appointment->package->price_per_month ?? '',
                        $appointment->status,
                        $appointment->scheduled_at?->toIso8601String() ?? '',
                        $appointment->notes ?? '',
                        $appointment->admin_notes ?? '',
                        $appointment->created_at?->toIso8601String() ?? '',
                    ]);
                }
            });

            fclose($handle);
        }, 'seo-appointments-export.csv', [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="seo-appointments-export.csv"',
        ]);
    }
}
