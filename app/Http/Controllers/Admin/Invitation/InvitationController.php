<?php

namespace App\Http\Controllers\Admin\Invitation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Invitation\AcceptInvitationRequest;
use App\Http\Requests\Invitation\SendInvitationRequest;
use App\Http\Resources\InvitationResource;
use App\Mail\StaffInvitationMail;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class InvitationController extends Controller
{
    /**
     * GET /api/admin/invitations/{token}/validate  (public)
     */
    public function validateToken(string $token): JsonResponse
    {
        $invitation = Invitation::where('token', $token)->with('inviter')->first();

        if (!$invitation) {
            return response()->json(['message' => 'Invitation not found.'], 404);
        }

        $valid = $invitation->isPending();

        return response()->json([
            'valid'      => $valid,
            'invitation' => new InvitationResource($invitation),
        ]);
    }

    /**
     * POST /api/admin/invitations/accept  (public)
     */
    public function accept(AcceptInvitationRequest $request): JsonResponse
    {
        $invitation = Invitation::where('token', $request->invitation_token)->first();

        if (!$invitation || !$invitation->isPending()) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors'  => [
                    'invitation_token' => ['This invitation has expired or has already been used.'],
                ],
            ], 422);
        }

        $default_organization = Organization::findDefault();

        $user = User::create([
            'first_name'      => $request->first_name,
            'last_name'       => $request->last_name,
            'email'           => $invitation->email,
            'password'        => $request->password,
            'organization_id' => $default_organization?->id,
        ]);

        $user->preference()->create();
        $user->billingAddress()->create();
        $user->assignRole($invitation->role);

        $invitation->update(['accepted_at' => now()]);

        /** @var string $token */
        $token = auth()->login($user);

        $user->load(['roles.permissions', 'organization']);

        return response()->json([
            'access_token' => $token,
            'token_type'   => 'bearer',
            'expires_in'   => auth()->factory()->getTTL() * 60,
            'user'         => $this->formatUser($user),
        ]);
    }

    /**
     * GET /api/admin/invitations
     */
    public function index(Request $request): JsonResponse
    {
        $query = Invitation::with('inviter');

        // Search by email
        if ($search = $request->query('search')) {
            $query->where('email', 'like', '%' . $search . '%');
        }

        // Filter by computed status
        if ($status = $request->query('status')) {
            match ($status) {
                'accepted' => $query->whereNotNull('accepted_at'),
                'expired'  => $query->whereNull('accepted_at')->where('expires_at', '<', now()),
                'pending'  => $query->whereNull('accepted_at')->where('expires_at', '>=', now()),
                default    => null,
            };
        }

        // Filter by role
        if ($role = $request->query('role')) {
            $query->where('role', $role);
        }

        // Filter by created_at date range
        if ($date_from = $request->query('date_from')) {
            $query->whereDate('created_at', '>=', $date_from);
        }

        if ($date_to = $request->query('date_to')) {
            $query->whereDate('created_at', '<=', $date_to);
        }

        // Sort
        $allowed_sort_fields = ['email', 'role', 'status', 'created_at', 'expires_at'];
        $sort_field          = $request->query('sort_field', 'created_at');
        $sort_direction      = in_array($request->query('sort_direction'), ['asc', 'desc'])
            ? $request->query('sort_direction')
            : 'desc';

        if (!in_array($sort_field, $allowed_sort_fields)) {
            $sort_field = 'created_at';
        }

        if ($sort_field === 'status') {
            $query->orderByRaw("
                CASE
                    WHEN accepted_at IS NOT NULL THEN 0
                    WHEN accepted_at IS NULL AND expires_at >= NOW() THEN 1
                    WHEN accepted_at IS NULL AND expires_at < NOW() THEN 2
                    ELSE 3
                END {$sort_direction}
            ");
        } else {
            $query->orderBy($sort_field, $sort_direction);
        }

        $paginated = $query->paginate(15);

        return response()->json([
            'data'         => collect($paginated->items())
                ->map(fn ($invitation) => (new InvitationResource($invitation))->resolve())
                ->values(),
            'current_page' => $paginated->currentPage(),
            'last_page'    => $paginated->lastPage(),
            'total'        => $paginated->total(),
        ]);
    }

    /**
     * POST /api/admin/invitations
     */
    public function store(SendInvitationRequest $request): JsonResponse
    {
        /** @var \App\Models\User $sender */
        $sender = auth()->user();

        // Admins can only invite staff, not other admins
        if ($sender->hasRole('admin') && !$sender->hasRole('super_admin') && $request->role === 'admin') {
            return response()->json([
                'message' => 'You are not authorized to invite users with the admin role.',
            ], 403);
        }

        if (User::where('email', $request->email)->exists()) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors'  => ['email' => ['A user with this email address already exists.']],
            ], 422);
        }

        if (Invitation::where('email', $request->email)->whereNull('accepted_at')->where('expires_at', '>', now())->exists()) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors'  => ['email' => ['A pending invitation for this email address already exists.']],
            ], 422);
        }

        $expires_days = (int) config('invitation.expires_days', 7);

        $invitation = Invitation::create([
            'email'      => $request->email,
            'role'       => $request->role,
            'token'      => Str::random(64),
            'invited_by' => $sender->id,
            'expires_at' => now()->addDays($expires_days),
        ]);

        $invitation->load('inviter');

        Mail::to($invitation->email)->send(new StaffInvitationMail($invitation));

        return response()->json(new InvitationResource($invitation), 201);
    }

    /**
     * DELETE /api/admin/invitations/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        $invitation = Invitation::find($id);

        if (!$invitation) {
            return response()->json(['message' => 'Invitation not found.'], 404);
        }

        if (!$invitation->isPending()) {
            return response()->json(['message' => 'Only pending invitations can be revoked.'], 422);
        }

        $invitation->delete();

        return response()->json(null, 204);
    }

    private function formatUser(User $user): array
    {
        return [
            'id'              => $user->id,
            'first_name'      => $user->first_name,
            'last_name'       => $user->last_name,
            'email'           => $user->email,
            'organization_id' => $user->organization_id,
            'organization'    => $user->organization,
            'roles'           => $user->roles->map(fn ($role) => [
                'id'           => $role->id,
                'name'         => $role->name,
                'display_name' => $role->display_name,
            ])->values(),
            'permissions'     => $user->getAllPermissions(),
            'created_at'      => $user->created_at,
            'updated_at'      => $user->updated_at,
        ];
    }
}
