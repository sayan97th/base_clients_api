<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateProfileRequest;
use App\Http\Requests\UploadProfilePhotoRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    public function show(): JsonResponse
    {
        $user = auth()->user();
        $preference = $user->preference;
        $billing = $user->billingAddress;

        return response()->json([
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'business_email' => $user->business_email,
            'phone' => $user->phone,
            'profile_photo_url' => $user->profile_photo_url,
            'timezone' => $preference?->timezone ?? 'UTC',
            'interested_in' => $this->mapInterestedInToFrontend($preference?->interested_in),
            'notification_channel' => $preference?->notification_channel ?? 'email_and_portal',
            'team_order_updates' => (bool) ($preference?->team_order_updates ?? true),
            'push_notifications_enabled' => (bool) ($preference?->push_notifications_enabled ?? false),
            'address' => $billing?->address,
            'city' => $billing?->city,
            'country' => $billing?->country,
            'state_province' => $billing?->state_province,
            'postal_code' => $billing?->postal_code,
            'company' => $billing?->company,
            'tax_id' => $billing?->tax_id,
        ]);
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = auth()->user();
        $validated = $request->validated();

        $user->update([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'business_email' => $validated['business_email'],
            'phone' => $validated['phone'] ?? null,
        ]);

        $user->preference()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'timezone' => $validated['timezone'],
                'interested_in' => $this->mapInterestedInToDatabase($validated['interested_in'] ?? ''),
                'notification_channel' => $validated['notification_channel'],
                'team_order_updates' => $validated['team_order_updates'],
                'push_notifications_enabled' => $validated['push_notifications_enabled'],
            ]
        );

        $user->billingAddress()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'address' => $validated['address'] ?? null,
                'city' => $validated['city'] ?? null,
                'country' => $validated['country'] ?? null,
                'state_province' => $validated['state_province'] ?? null,
                'postal_code' => $validated['postal_code'] ?? null,
                'company' => $validated['company'] ?? null,
                'tax_id' => $validated['tax_id'] ?? null,
            ]
        );

        $user->load(['roles:id,name,display_name', 'organization']);

        return response()->json([
            'user' => $user,
            'message' => 'Profile updated successfully.',
        ]);
    }

    public function uploadPhoto(UploadProfilePhotoRequest $request): JsonResponse
    {
        $user = auth()->user();

        if ($user->profile_photo_url) {
            $old_path = str_replace('/storage/', '', parse_url($user->profile_photo_url, PHP_URL_PATH));
            Storage::disk('public')->delete($old_path);
        }

        $path = $request->file('profile_photo')->store('profile-photos', 'public');

        $user->update([
            'profile_photo_url' => asset('storage/' . $path),
        ]);

        $user->load(['roles:id,name,display_name', 'organization']);

        return response()->json([
            'user' => $user,
            'message' => 'Profile photo updated successfully.',
        ]);
    }

    public function deletePhoto(): JsonResponse
    {
        $user = auth()->user();

        if ($user->profile_photo_url) {
            $old_path = str_replace('/storage/', '', parse_url($user->profile_photo_url, PHP_URL_PATH));
            Storage::disk('public')->delete($old_path);
        }

        $user->update([
            'profile_photo_url' => null,
        ]);

        $user->load(['roles:id,name,display_name', 'organization']);

        return response()->json([
            'user' => $user,
            'message' => 'Profile photo removed successfully.',
        ]);
    }

    private function mapInterestedInToFrontend(?string $value): string
    {
        return match ($value) {
            'links' => 'Links',
            'content' => 'Content',
            'both' => 'Both',
            default => '',
        };
    }

    private function mapInterestedInToDatabase(?string $value): string
    {
        return match ($value) {
            'Links' => 'links',
            'Content' => 'content',
            'Both' => 'both',
            default => 'nothing',
        };
    }
}
