<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Looks up the title of a Google Docs, Sheets, Slides or Drive file from its
 * link, like the preview Google Sheets shows when hovering a Docs link in the
 * Article column of the BASE Google Sheet.
 *
 * The title is read from the public page of the file, so it is only known for
 * files shared with "Anyone with the link". Private files come back with the
 * "private" status, and the caller falls back to the row's Article Title.
 *
 * Only Google hosts are ever requested: the file id is taken from the link and
 * the page URL is rebuilt from it, so a crafted link cannot reach another host.
 */
class GoogleDocTitleService
{
    public const STATUS_OK          = 'ok';
    public const STATUS_PRIVATE     = 'private';
    public const STATUS_UNAVAILABLE = 'unavailable';

    /** Titles rarely change, so a found title is kept for a week. */
    private const FOUND_TTL_HOURS = 24 * 7;

    /** A private or deleted file may be shared later, so it is checked again sooner. */
    private const PRIVATE_TTL_HOURS = 6;

    /** Network errors are retried soon after. */
    private const UNAVAILABLE_TTL_MINUTES = 10;

    private const REQUEST_TIMEOUT_SECONDS = 6;

    /** Bump to drop every cached title, e.g. after changing how titles are parsed. */
    private const CACHE_VERSION = 'v1';

    /** Docs editors whose links look like docs.google.com/{kind}/d/{id}. */
    private const DOCS_KINDS = ['document', 'spreadsheets', 'presentation', 'forms'];

    /** Suffixes Google adds to the page <title>, in English and the locales seen so far. */
    private const TITLE_SUFFIX_PATTERN = '/\s+-\s+(Google (Docs|Sheets|Slides|Forms|Drive)|Documentos de Google|Hojas de cálculo de Google|Presentaciones de Google)$/u';

    /**
     * The file a Google link points to, or null when it is not a Google Docs or
     * Drive file link.
     *
     * @return array{kind: string, id: string}|null
     */
    public function parseLink(string $url): ?array
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';

        if ($host === 'docs.google.com') {
            $kinds = implode('|', self::DOCS_KINDS);
            // The optional /u/{n} segment is added when a signed in user copies the link.
            if (preg_match("~^(?:/u/\d+)?/($kinds)(?:/u/\d+)?/d/([A-Za-z0-9_-]{10,})~", $path, $matches)) {
                return ['kind' => $matches[1], 'id' => $matches[2]];
            }
            return null;
        }

        if ($host === 'drive.google.com') {
            if (preg_match('~^(?:/u/\d+)?/file/d/([A-Za-z0-9_-]{10,})~', $path, $matches)) {
                return ['kind' => 'file', 'id' => $matches[1]];
            }
            parse_str($parts['query'] ?? '', $query);
            $id = $query['id'] ?? null;
            if (is_string($id) && preg_match('~^[A-Za-z0-9_-]{10,}$~', $id) && preg_match('~^/(open|uc)$~', $path)) {
                return ['kind' => 'file', 'id' => $id];
            }
        }

        return null;
    }

    /**
     * Title of the linked file, cached per file id.
     *
     * @param  array{kind: string, id: string}  $link
     * @return array{title: string|null, status: string}
     */
    public function lookup(array $link): array
    {
        $cache_key = 'google_doc_title_' . self::CACHE_VERSION . "_{$link['kind']}_{$link['id']}";

        $cached = Cache::get($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $result = $this->fetchTitle($link);

        $ttl = match ($result['status']) {
            self::STATUS_OK      => now()->addHours(self::FOUND_TTL_HOURS),
            self::STATUS_PRIVATE => now()->addHours(self::PRIVATE_TTL_HOURS),
            default              => now()->addMinutes(self::UNAVAILABLE_TTL_MINUTES),
        };
        Cache::put($cache_key, $result, $ttl);

        return $result;
    }

    /**
     * @param  array{kind: string, id: string}  $link
     * @return array{title: string|null, status: string}
     */
    private function fetchTitle(array $link): array
    {
        $page_url = $link['kind'] === 'file'
            ? "https://drive.google.com/file/d/{$link['id']}/view"
            : "https://docs.google.com/{$link['kind']}/d/{$link['id']}/edit";

        try {
            // Redirects are not followed: a private file redirects to the Google
            // sign in page, whose title is not the file's.
            $response = Http::timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->withOptions(['allow_redirects' => false])
                ->withHeaders(['Accept-Language' => 'en-US,en;q=0.9'])
                ->get($page_url);
        } catch (ConnectionException) {
            return ['title' => null, 'status' => self::STATUS_UNAVAILABLE];
        }

        if ($response->redirect() || in_array($response->status(), [401, 403, 404], true)) {
            return ['title' => null, 'status' => self::STATUS_PRIVATE];
        }

        if (! $response->successful()) {
            return ['title' => null, 'status' => self::STATUS_UNAVAILABLE];
        }

        $title = $this->extractTitle($response->body());

        return $title === null
            ? ['title' => null, 'status' => self::STATUS_PRIVATE]
            : ['title' => $title, 'status' => self::STATUS_OK];
    }

    /** The og:title of the page, else its <title> without the Google suffix. */
    private function extractTitle(string $html): ?string
    {
        $title = null;

        if (preg_match('~<meta\s+property="og:title"\s+content="([^"]*)"~i', $html, $matches)) {
            $title = $matches[1];
        } elseif (preg_match('~<title>([^<]*)</title>~i', $html, $matches)) {
            $title = preg_replace(self::TITLE_SUFFIX_PATTERN, '', trim($matches[1]));
        }

        if ($title === null) {
            return null;
        }

        $title = trim(html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        // The sign in page and generic shells carry the product name only.
        if ($title === '' || preg_match('~^(Google (Docs|Sheets|Slides|Forms|Drive)|Sign in.*|Meet Google Drive.*)$~i', $title)) {
            return null;
        }

        return mb_substr($title, 0, 500);
    }
}
