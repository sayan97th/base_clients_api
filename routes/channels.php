<?php

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Str;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('notifications.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});

// Presence channel for real-time backlink order collaboration.
// Returns user data so every connected tab knows who else is viewing the table.
// The session_id is a per-connection UUID that the frontend uses as a tab-level
// unique identifier (distinct from user_id, which is shared across tabs).
Broadcast::channel('backlink-orders', function ($user) {
    if (! $user->hasRole(['super_admin', 'admin', 'staff'])) {
        return false;
    }

    return [
        'session_id' => request()->header('X-Session-ID', (string) Str::uuid()),
        'user_id'    => $user->id,
        'name'       => $user->first_name . ' ' . $user->last_name,
        'initials'   => strtoupper(substr($user->first_name, 0, 1) . substr($user->last_name, 0, 1)),
        'color'      => $user->presence_color ?? '#6366f1',
        'avatar_url' => $user->profile_photo_url,
    ];
});
