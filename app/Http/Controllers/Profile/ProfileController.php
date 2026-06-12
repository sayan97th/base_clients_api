<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserProfile\PatchProfileRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function show(): JsonResponse
    {
        $user       = auth()->user();
        $preference = $user->preference;
        $billing    = $user->billingAddress;

        return response()->json([
            'data' => [
                'id'                          => $user->id,
                'name'                        => $user->full_name,
                'email'                       => $user->email,
                'first_name'                  => $user->first_name,
                'last_name'                   => $user->last_name,
                'business_email'              => $user->business_email,
                'phone'                       => $user->phone,
                'timezone'                    => $preference?->timezone ?? null,
                'interested_in'               => $this->mapInterestedIn($preference?->interested_in),
                'notification_channel'        => $preference?->notification_channel ?? null,
                'team_order_updates'          => isset($preference->team_order_updates)
                                                    ? (bool) $preference->team_order_updates
                                                    : null,
                'push_notifications_enabled'  => isset($preference->push_notifications_enabled)
                                                    ? (bool) $preference->push_notifications_enabled
                                                    : null,
                'address'                     => $billing?->address,
                'city'                        => $billing?->city,
                'country'                     => $billing?->country,
                'state_province'              => $billing?->state_province,
                'postal_code'                 => $billing?->postal_code,
                'company'                     => $user->company ?? $billing?->company,
                'google_studio_link'          => $user->google_studio_link,
                'note'                        => $user->note,
                'profile_photo_path'          => $user->profile_photo_path,
                'profile_photo_url'           => $user->profile_photo_url,
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'first_name'                  => ['required', 'string', 'max:100'],
            'last_name'                   => ['required', 'string', 'max:100'],
            'business_email'              => ['nullable', 'email', 'max:255'],
            'phone'                       => ['nullable', 'string'],
            'timezone'                    => ['nullable', 'string'],
            'interested_in'               => ['nullable', 'string', Rule::in(['', 'links', 'content', 'both'])],
            'notification_channel'        => ['required', 'string', Rule::in(['email_and_portal', 'email', 'portal'])],
            'team_order_updates'          => ['required', 'boolean'],
            'push_notifications_enabled'  => ['required', 'boolean'],
            'address'                     => ['nullable', 'string', 'max:255'],
            'city'                        => ['nullable', 'string', 'max:100'],
            'country'                     => ['nullable', 'string', 'max:10'],
            'state_province'              => ['nullable', 'string', 'max:100'],
            'postal_code'                 => ['nullable', 'string', 'max:20'],
            'company'                     => ['nullable', 'string', 'max:255'],
            'google_studio_link'          => ['nullable', 'string', 'max:500'],
        ]);

        $user = auth()->user();

        $user->update([
            'first_name'         => $validated['first_name'],
            'last_name'          => $validated['last_name'],
            'business_email'     => $validated['business_email'] ?? null,
            'phone'              => $validated['phone'] ?? null,
            'company'            => $validated['company'] ?? null,
            'google_studio_link' => $validated['google_studio_link'] ?? null,
        ]);

        $user->preference()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'timezone'                   => $validated['timezone'] ?? null,
                'interested_in'              => $this->mapInterestedInToDatabase($validated['interested_in'] ?? ''),
                'notification_channel'       => $validated['notification_channel'],
                'team_order_updates'         => $validated['team_order_updates'],
                'push_notifications_enabled' => $validated['push_notifications_enabled'],
            ]
        );

        $user->billingAddress()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'address'        => $validated['address'] ?? null,
                'city'           => $validated['city'] ?? null,
                'country'        => $validated['country'] ?? null,
                'state_province' => $validated['state_province'] ?? null,
                'postal_code'    => $validated['postal_code'] ?? null,
                'company'        => $validated['company'] ?? null,
            ]
        );

        $user->refresh();

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user'    => [
                'id'                 => $user->id,
                'first_name'         => $user->first_name,
                'last_name'          => $user->last_name,
                'email'              => $user->email,
                'business_email'     => $user->business_email,
                'phone'              => $user->phone,
                'company'            => $user->company,
                'google_studio_link' => $user->google_studio_link,
                'profile_photo_url'  => $user->profile_photo_url,
            ],
        ]);
    }

    public function partialUpdate(PatchProfileRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user      = auth()->user();

        $user_fields = array_intersect_key($validated, array_flip([
            'first_name', 'last_name', 'business_email', 'phone', 'company', 'google_studio_link',
        ]));

        if (!empty($user_fields)) {
            $user->update($user_fields);
        }

        $preference_fields = array_intersect_key($validated, array_flip([
            'timezone', 'interested_in', 'notification_channel',
            'team_order_updates', 'push_notifications_enabled',
        ]));

        if (!empty($preference_fields)) {
            if (array_key_exists('interested_in', $preference_fields)) {
                $preference_fields['interested_in'] = $this->mapInterestedInToDatabase(
                    $preference_fields['interested_in']
                );
            }

            $user->preference()->updateOrCreate(
                ['user_id' => $user->id],
                $preference_fields
            );
        }

        $billing_fields = array_intersect_key($validated, array_flip([
            'address', 'city', 'country', 'state_province', 'postal_code', 'company',
        ]));

        if (!empty($billing_fields)) {
            $user->billingAddress()->updateOrCreate(
                ['user_id' => $user->id],
                $billing_fields
            );
        }

        $user->refresh();

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user'    => [
                'id'                 => $user->id,
                'first_name'         => $user->first_name,
                'last_name'          => $user->last_name,
                'email'              => $user->email,
                'business_email'     => $user->business_email,
                'phone'              => $user->phone,
                'company'            => $user->company,
                'google_studio_link' => $user->google_studio_link,
                'profile_photo_url'  => $user->profile_photo_url,
            ],
        ]);
    }

    private function mapInterestedIn(?string $value): ?string
    {
        return match ($value) {
            'links', 'content', 'both' => $value,
            default                    => null,
        };
    }

    private function mapInterestedInToDatabase(?string $value): string
    {
        return match (strtolower(trim($value ?? ''))) {
            'links'   => 'links',
            'content' => 'content',
            'both'    => 'both',
            default   => 'nothing',
        };
    }
}
