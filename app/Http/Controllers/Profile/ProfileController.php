<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user       = auth()->user();
        $preference = $user->preference;
        $billing    = $user->billingAddress;

        return response()->json([
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
            'company'                     => $billing?->company,
            'tax_id'                      => $billing?->tax_id,
            'profile_photo_path'          => $user->profile_photo_path,
            'profile_photo_url'           => $user->profile_photo_url,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'first_name'                  => ['sometimes', 'string', 'max:255'],
            'last_name'                   => ['sometimes', 'string', 'max:255'],
            'business_email'              => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone'                       => ['sometimes', 'nullable', 'string', 'max:50'],
            'timezone'                    => ['sometimes', 'nullable', 'string', 'max:100'],
            'interested_in'               => ['sometimes', 'nullable', Rule::in(['', 'links', 'content', 'both'])],
            'notification_channel'        => ['sometimes', 'string', Rule::in(['email_and_portal', 'portal_only'])],
            'team_order_updates'          => ['sometimes', 'boolean'],
            'push_notifications_enabled'  => ['sometimes', 'boolean'],
            'address'                     => ['sometimes', 'nullable', 'string', 'max:255'],
            'city'                        => ['sometimes', 'nullable', 'string', 'max:100'],
            'country'                     => ['sometimes', 'nullable', 'string', 'max:10'],
            'state_province'              => ['sometimes', 'nullable', 'string', 'max:100'],
            'postal_code'                 => ['sometimes', 'nullable', 'string', 'max:20'],
            'company'                     => ['sometimes', 'nullable', 'string', 'max:255'],
            'tax_id'                      => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $user = auth()->user();

        $userFields = array_intersect_key($validated, array_flip([
            'first_name', 'last_name', 'business_email', 'phone',
        ]));

        if (!empty($userFields)) {
            $user->update($userFields);
        }

        $preferenceFields = array_intersect_key($validated, array_flip([
            'timezone', 'interested_in', 'notification_channel',
            'team_order_updates', 'push_notifications_enabled',
        ]));

        if (!empty($preferenceFields)) {
            if (array_key_exists('interested_in', $preferenceFields)) {
                $preferenceFields['interested_in'] = $this->mapInterestedInToDatabase(
                    $preferenceFields['interested_in']
                );
            }

            $user->preference()->updateOrCreate(
                ['user_id' => $user->id],
                $preferenceFields
            );
        }

        $billingFields = array_intersect_key($validated, array_flip([
            'address', 'city', 'country', 'state_province',
            'postal_code', 'company', 'tax_id',
        ]));

        if (!empty($billingFields)) {
            $user->billingAddress()->updateOrCreate(
                ['user_id' => $user->id],
                $billingFields
            );
        }

        $user->load(['roles:id,name,display_name', 'organization']);

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user'    => $user,
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
