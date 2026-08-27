<?php

namespace App\Support;

/**
 * Single source of truth for building links back to the base_portal frontend.
 * base_portal is one Next.js app (admin and client routes are route groups
 * within it, not separate deployments), so every outgoing email/notification
 * link must resolve against the same FRONTEND_URL, regardless of whether the
 * link points at an "/admin/..." or a client-facing path.
 */
class FrontendUrl
{
    public static function to(string $path = ''): string
    {
        $base_url = rtrim(config('app.frontend_url'), '/');

        if ($path === '') {
            return $base_url;
        }

        return $base_url . '/' . ltrim($path, '/');
    }
}
