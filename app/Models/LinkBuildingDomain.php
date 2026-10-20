<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A partnership domain and its niche, used by the link building orders dashboard
 * to show (and auto fill) the niche of the domain chosen as an order's Partnership.
 */
class LinkBuildingDomain extends Model
{
    use HasUuids;

    protected $table = 'link_building_domains';

    protected $fillable = [
        'domain',
        'niche',
    ];

    /**
     * Reduces a domain or URL to its bare host, so "https://www.Example.com/blog"
     * and "example.com" resolve to the same catalog entry. Returns null when the
     * value does not look like a domain.
     */
    public static function normalizeDomain(?string $value): ?string
    {
        $value = strtolower(trim((string) $value));

        if ($value === '') {
            return null;
        }

        $value = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $value);
        $value = preg_replace('#^www\.#', '', $value);
        $value = preg_split('#[/?\#:\s]#', $value)[0] ?? '';
        $value = rtrim($value, '.');

        return str_contains($value, '.') ? $value : null;
    }

    /** Niche saved for the domain of a URL, or null when the domain is not in the catalog. */
    public static function nicheFor(?string $url): ?string
    {
        $domain = static::normalizeDomain($url);

        if ($domain === null) {
            return null;
        }

        $niche = static::where('domain', $domain)->value('niche');

        return filled($niche) ? (string) $niche : null;
    }

    /**
     * Saves the niche of a URL's domain, creating the catalog entry when needed.
     * A blank niche never erases a saved one, clearing a single order's Niche
     * cell should not remove the niche every other order of that domain uses.
     */
    public static function rememberNiche(?string $url, ?string $niche): void
    {
        $domain = static::normalizeDomain($url);
        $niche  = trim((string) $niche);

        if ($domain === null || $niche === '') {
            return;
        }

        static::updateOrCreate(['domain' => $domain], ['niche' => $niche]);
    }
}
