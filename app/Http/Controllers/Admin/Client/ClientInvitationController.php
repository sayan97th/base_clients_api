<?php

namespace App\Http\Controllers\Admin\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Client\SendClientInvitationRequest;
use App\Http\Resources\InvitationResource;
use App\Mail\ClientInvitationMail;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ClientInvitationController extends Controller
{
    /**
     * GET /api/admin/client-invitations
     */
    public function index(Request $request): JsonResponse
    {
        $query = Invitation::with('inviter')->where('role', 'client');

        if ($search = $request->query('search')) {
            $query->where('email', 'like', '%' . $search . '%');
        }

        if ($status = $request->query('status')) {
            match ($status) {
                'accepted' => $query->whereNotNull('accepted_at'),
                'expired'  => $query->whereNull('accepted_at')->where('expires_at', '<', now()),
                'pending'  => $query->whereNull('accepted_at')->where('expires_at', '>=', now()),
                default    => null,
            };
        }

        if ($date_from = $request->query('date_from')) {
            $query->whereDate('created_at', '>=', $date_from);
        }

        if ($date_to = $request->query('date_to')) {
            $query->whereDate('created_at', '<=', $date_to);
        }

        $allowed_sort_fields = ['email', 'status', 'created_at', 'expires_at'];
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
                ->map(fn ($inv) => $this->formatInvitation($inv))
                ->values(),
            'current_page' => $paginated->currentPage(),
            'last_page'    => $paginated->lastPage(),
            'total'        => $paginated->total(),
        ]);
    }

    /**
     * POST /api/admin/client-invitations
     */
    public function store(SendClientInvitationRequest $request): JsonResponse
    {
        /** @var \App\Models\User $sender */
        $sender = auth()->user();

        if (User::where('email', $request->email)->exists()) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors'  => ['email' => ['A user with this email address already exists.']],
            ], 422);
        }

        if (Invitation::where('email', $request->email)
            ->where('role', 'client')
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->exists()
        ) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors'  => ['email' => ['A pending invitation for this email already exists.']],
            ], 422);
        }

        $expires_days = (int) config('invitation.expires_days', 7);

        $invitation = Invitation::create([
            'email'      => $request->email,
            'role'       => 'client',
            'token'      => Str::random(64),
            'invited_by' => $sender->id,
            'expires_at' => now()->addDays($expires_days),
        ]);

        $invitation->load('inviter');

        Mail::to($invitation->email)->send(new ClientInvitationMail($invitation));

        return response()->json($this->formatInvitation($invitation), 201);
    }

    /**
     * POST /api/admin/client-invitations/{id}/resend
     */
    public function resend(int $id): JsonResponse
    {
        $invitation = Invitation::where('id', $id)->where('role', 'client')->first();

        if (!$invitation) {
            return response()->json(['message' => 'Invitation not found.'], 404);
        }

        if ($invitation->isAccepted()) {
            return response()->json(['message' => 'This invitation has already been accepted.'], 422);
        }

        $expires_days = (int) config('invitation.expires_days', 7);

        $invitation->update([
            'token'      => Str::random(64),
            'expires_at' => now()->addDays($expires_days),
        ]);

        $invitation->load('inviter');

        Mail::to($invitation->email)->send(new ClientInvitationMail($invitation));

        return response()->json([
            'message'    => 'Invitation resent successfully.',
            'invitation' => $this->formatInvitation($invitation),
        ]);
    }

    /**
     * DELETE /api/admin/client-invitations/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        $invitation = Invitation::where('id', $id)->where('role', 'client')->first();

        if (!$invitation) {
            return response()->json(['message' => 'Invitation not found.'], 404);
        }

        if ($invitation->isAccepted()) {
            return response()->json(['message' => 'Accepted invitations cannot be revoked.'], 422);
        }

        $invitation->delete();

        return response()->json(null, 204);
    }

    private function formatInvitation(Invitation $invitation): array
    {
        if ($invitation->isAccepted()) {
            $status = 'accepted';
        } elseif ($invitation->isExpired()) {
            $status = 'expired';
        } else {
            $status = 'pending';
        }

        return [
            'id'          => $invitation->id,
            'email'       => $invitation->email,
            'role'        => $invitation->role,
            'token'       => $invitation->token,
            'invited_by'  => $invitation->invited_by,
            'accepted_at' => $invitation->accepted_at,
            'expires_at'  => $invitation->expires_at,
            'created_at'  => $invitation->created_at,
            'updated_at'  => $invitation->updated_at,
            'status'      => $status,
            'inviter'     => $invitation->inviter ? [
                'id'         => $invitation->inviter->id,
                'first_name' => $invitation->inviter->first_name,
                'last_name'  => $invitation->inviter->last_name,
                'email'      => $invitation->inviter->email,
            ] : null,
        ];
    }
}
