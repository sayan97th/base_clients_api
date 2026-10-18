<?php

namespace App\Http\Controllers\Admin\EmailNotification;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmailNotification\UpdateUserEmailPreferenceRequest;
use App\Models\User;
use App\Models\UserEmailPreference;
use App\Services\EmailDeliveryService;
use App\Support\Email\EmailTypeCatalog;
use Illuminate\Http\JsonResponse;

class UserEmailPreferenceController extends Controller
{
    /**
     * Lists every active admin side user with their email preferences, plus
     * the email types that can reach the admin side, so the UI can render
     * the per-user matrix from a single request.
     */
    public function index(): JsonResponse
    {
        $users = User::whereHas('roles', fn ($q) => $q->whereIn('name', EmailDeliveryService::ADMIN_ROLES))
            ->where('is_active', true)
            ->with(['roles:id,name,display_name', 'emailPreference'])
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'email']);

        return response()->json([
            'categories'  => EmailTypeCatalog::categoryList(),
            'email_types' => EmailDeliveryService::presentEmailTypes(EmailTypeCatalog::forAdminAudience()),
            'users'       => $users->map(fn (User $user) => $this->formatUser($user))->values(),
        ]);
    }

    public function update(UpdateUserEmailPreferenceRequest $request, int $user_id): JsonResponse
    {
        $user = User::findOrFail($user_id);

        // Client side users always receive their emails, so they have no
        // preferences to manage here.
        if (! $user->hasRole(EmailDeliveryService::ADMIN_ROLES)) {
            return response()->json([
                'message' => 'Email preferences can only be managed for admin side users. Client side users always receive their emails.',
            ], 422);
        }

        $validated = $request->validated();

        $preference = UserEmailPreference::updateOrCreate(
            ['user_id' => $user->id],
            [
                'mute_all_emails'      => $validated['mute_all_emails'],
                'disabled_email_types' => array_values(array_unique($validated['disabled_email_types'])),
                'updated_by_user_id'   => $request->user()->id,
            ],
        );

        EmailDeliveryService::invalidateCache();

        return response()->json([
            'message'    => 'Email preferences updated successfully',
            'preference' => [
                'mute_all_emails'      => $preference->mute_all_emails,
                'disabled_email_types' => $preference->disabled_email_types,
            ],
        ]);
    }

    private function formatUser(User $user): array
    {
        $role = $user->roles->first();

        return [
            'id'                   => $user->id,
            'first_name'           => $user->first_name,
            'last_name'            => $user->last_name,
            'email'                => $user->email,
            'role_display'         => $role?->display_name ?: ($role?->name ?? 'Staff'),
            'mute_all_emails'      => $user->emailPreference?->mute_all_emails ?? false,
            'disabled_email_types' => $user->emailPreference?->disabled_email_types ?? [],
        ];
    }
}
