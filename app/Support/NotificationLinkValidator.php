<?php

namespace App\Support;

/**
 * Validates the free-text `link` column stored on notifications before it is ever
 * turned into an email CTA or a frontend redirect target. `link` is written by many
 * different call sites across the codebase (see NotificationService::createNotification
 * callers), so it cannot be trusted as safe by construction, only a path that passes
 * this check may be used to build a URL a user's browser will actually navigate to.
 */
class NotificationLinkValidator
{
    private const MAX_LENGTH = 2048;

    /**
     * Allows path, query string, and fragment characters produced by the frontend
     * router. Deliberately excludes backslashes and a second leading slash, the
     * building blocks of a protocol-relative open redirect (e.g. "//evil.com").
     */
    private const SAFE_PATH_PATTERN = '/^\/[A-Za-z0-9\-_.~\/?=&%#]*$/';

    /**
     * Returns the path unchanged when it is a safe, same-origin relative path
     * (starts with a single "/", no control characters, no backslashes, no
     * protocol-relative prefix), or null when it is missing or unsafe.
     */
    public static function sanitizeRelativePath(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        $path = trim($path);

        if ($path === '' || mb_strlen($path) > self::MAX_LENGTH) {
            return null;
        }

        if (! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return null;
        }

        if (preg_match('/[\x00-\x1F\x7F\\\\]/', $path)) {
            return null;
        }

        if (! preg_match(self::SAFE_PATH_PATTERN, $path)) {
            return null;
        }

        return $path;
    }

    /**
     * Returns "scheme://host[:port]" for $url, or null when it cannot be parsed as
     * an absolute URL. Comparing full origins (not just host) matters here because
     * the admin and client portals commonly share a hostname in local/staging
     * environments and differ only by port (e.g. localhost:3000 vs localhost:3001),
     * comparing hosts alone would treat them as the same domain.
     */
    public static function parseOrigin(string $url): ?string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host   = parse_url($url, PHP_URL_HOST);

        if (! $scheme || ! $host) {
            return null;
        }

        $port = parse_url($url, PHP_URL_PORT);

        return strtolower($scheme) . '://' . strtolower($host) . ($port ? ':' . $port : '');
    }

    /**
     * Returns true when $url is an absolute URL whose origin matches one of our own
     * portal domains (frontend or admin). Used to stop a stored absolute link from
     * turning an email button into an open redirect to an arbitrary external host.
     *
     * @param string[] $allowed_origins
     */
    public static function isAllowedAbsoluteUrl(string $url, array $allowed_origins): bool
    {
        $origin = self::parseOrigin($url);

        return $origin !== null && in_array($origin, $allowed_origins, true);
    }
}
