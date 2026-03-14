<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProfilePhotoController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'profile_photo' => ['required', 'image', 'mimes:jpeg,png,gif,webp', 'max:2048'],
        ]);

        $user = auth()->user();

        if ($user->profile_photo_path) {
            Storage::disk('public')->delete($user->profile_photo_path);
        }

        $file      = $request->file('profile_photo');
        $extension = $file->getClientOriginalExtension();
        $filename  = Str::uuid() . '.' . $extension;
        $path      = $file->storeAs('profile-photos', $filename, 'public');

        $user->update(['profile_photo_path' => $path]);

        $user->load(['roles:id,name,display_name', 'organization']);

        return response()->json([
            'message' => 'Profile photo updated successfully.',
            'user'    => $user,
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = auth()->user();

        if ($user->profile_photo_path) {
            Storage::disk('public')->delete($user->profile_photo_path);
        }

        $user->update(['profile_photo_path' => null]);

        $user->load(['roles:id,name,display_name', 'organization']);

        return response()->json([
            'message' => 'Profile photo removed successfully.',
            'user'    => $user,
        ]);
    }
}
