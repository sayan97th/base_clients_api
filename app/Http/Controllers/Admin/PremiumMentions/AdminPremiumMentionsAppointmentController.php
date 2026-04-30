<?php

namespace App\Http\Controllers\Admin\PremiumMentions;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PremiumMentions\UpdatePremiumMentionsAppointmentRequest;
use App\Http\Resources\AdminPremiumMentionsAppointmentResource;
use App\Models\PremiumMentionsAppointment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPremiumMentionsAppointmentController extends Controller
{
    private array $eagerLoad = [
        'user:id,first_name,last_name,email,organization_id',
        'user.organization:id,name',
        'plan:id,name,price_per_month',
    ];

    public function index(Request $request): JsonResponse
    {
        $query = PremiumMentionsAppointment::with($this->eagerLoad);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('user', function ($user_q) use ($search) {
                    $user_q->where('first_name', 'like', "%{$search}%")
                           ->orWhere('last_name', 'like', "%{$search}%")
                           ->orWhere('email', 'like', "%{$search}%");
                })->orWhere('event_uri', 'like', "%{$search}%");
            });
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($plan_id = $request->query('plan_id')) {
            $query->where('plan_id', $plan_id);
        }

        if ($date_from = $request->query('date_from')) {
            $query->whereDate('scheduled_at', '>=', $date_from);
        }

        if ($date_to = $request->query('date_to')) {
            $query->whereDate('scheduled_at', '<=', $date_to);
        }

        $sort_field = in_array($request->query('sort_field'), ['scheduled_at', 'created_at', 'status'])
            ? $request->query('sort_field')
            : 'scheduled_at';

        $sort_direction = $request->query('sort_direction', 'desc') === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sort_field, $sort_direction);

        $per_page  = min((int) $request->query('per_page', 15), 100);
        $paginated = $query->paginate($per_page);

        return response()->json([
            'data' => [
                'data'         => AdminPremiumMentionsAppointmentResource::collection($paginated->items()),
                'total'        => $paginated->total(),
                'last_page'    => $paginated->lastPage(),
                'current_page' => $paginated->currentPage(),
                'per_page'     => $paginated->perPage(),
            ],
        ]);
    }

    public function stats(): JsonResponse
    {
        $total     = PremiumMentionsAppointment::count();
        $by_status = PremiumMentionsAppointment::selectRaw('status, COUNT(*) as count')
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

    public function show(int $id): JsonResponse
    {
        $appointment = PremiumMentionsAppointment::with($this->eagerLoad)->find($id);

        if (!$appointment) {
            return response()->json(['message' => 'Appointment not found.'], 404);
        }

        return response()->json(['data' => new AdminPremiumMentionsAppointmentResource($appointment)]);
    }

    public function update(UpdatePremiumMentionsAppointmentRequest $request, int $id): JsonResponse
    {
        $appointment = PremiumMentionsAppointment::with($this->eagerLoad)->find($id);

        if (!$appointment) {
            return response()->json(['message' => 'Appointment not found.'], 404);
        }

        if ($request->has('status')) {
            $new_status = $request->status;

            if ($new_status !== $appointment->status && !$appointment->canTransitionTo($new_status)) {
                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors'  => [
                        'status' => ["Cannot transition from '{$appointment->status}' to '{$new_status}'."],
                    ],
                ], 422);
            }
        }

        $appointment->fill($request->only(['status', 'scheduled_at', 'admin_notes', 'notes']));
        $appointment->save();

        return response()->json(['data' => new AdminPremiumMentionsAppointmentResource($appointment)]);
    }

    public function destroy(int $id): JsonResponse
    {
        $appointment = PremiumMentionsAppointment::find($id);

        if (!$appointment) {
            return response()->json(['message' => 'Appointment not found.'], 404);
        }

        $appointment->delete();

        return response()->json(['message' => 'Appointment deleted successfully.']);
    }
}
