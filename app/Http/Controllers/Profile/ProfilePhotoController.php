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
            Storage::disk(config('filesystems.app_disk'))->delete($user->profile_photo_path);
        }

        $file      = $request->file('profile_photo');
        $extension = $file->getClientOriginalExtension();
        $filename  = Str::uuid() . '.' . $extension;
        $path      = $file->storeAs('profile-photos', $filename, config('filesystems.app_disk'));

        $user->update(['profile_photo_path' => $path]);

        $user->refresh();

        return response()->json([
            'message' => 'Profile photo updated successfully.',
            'user'    => [
                'id'                => $user->id,
                'first_name'        => $user->first_name,
                'last_name'         => $user->last_name,
                'email'             => $user->email,
                'profile_photo_url' => $user->profile_photo_url,
            ],
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = auth()->user();

        if ($user->profile_photo_path) {
            Storage::disk(config('filesystems.app_disk'))->delete($user->profile_photo_path);
        }

        $user->update(['profile_photo_path' => null]);

        return response()->json([
            'message' => 'Profile photo removed successfully.',
            'user'    => [
                'id'                => $user->id,
                'first_name'        => $user->first_name,
                'last_name'         => $user->last_name,
                'email'             => $user->email,
                'profile_photo_url' => null,
            ],
        ]);
    }
}
