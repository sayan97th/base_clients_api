<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('notifications.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});

// Public channel for real-time backlink order collaboration.
// All authenticated admin/staff users may subscribe.
Broadcast::channel('backlink-orders', function ($user) {
    return $user->hasAnyRole(['super_admin', 'admin', 'staff']);
});
