<?php

namespace App\Http\Controllers\Admin\LinkBuilding;

use App\Http\Controllers\Controller;
use App\Models\LinkBuildingDomain;
use App\Models\LinkBuildingOrderPlacement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Partnership domain lookups for the link building orders dashboard: the domain
 * picker shown while editing a Partnership cell, and the Niche cell options.
 */
class LinkBuildingDomainController extends Controller
{
    private const SUGGESTIONS_DEFAULT_LIMIT = 20;
    private const SUGGESTIONS_MAX_LIMIT     = 50;

    /** Most recent orders read to build the per domain order history of the picker. */
    private const HISTORY_ROW_LIMIT = 1000;

    /**
     * GET /api/admin/link-building-orders/partnership-domains
     *
     * Domains offered while choosing a Partnership, each with its niche and the
     * metrics of the latest order that used it, so the admin can see what a domain
     * is about before picking it. Combines the domain catalog with every domain
     * already used on an order.
     *
     * Query:
     *   search : string  optional, part of the domain
     *   limit  : int     optional, defaults to 20, at most 50
     *
     * Response:
     *   data : [{ domain, niche, dr, traffic, price, order_count }]
     */
    public function partnershipDomains(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'limit'  => ['nullable', 'integer', 'min:1', 'max:' . self::SUGGESTIONS_MAX_LIMIT],
        ]);

        $search = $this->normalizeSearch((string) ($validated['search'] ?? ''));
        $limit  = (int) ($validated['limit'] ?? self::SUGGESTIONS_DEFAULT_LIMIT);

        $suggestions = $this->orderHistoryByDomain($search);

        $catalog_query = LinkBuildingDomain::query()->orderBy('domain');
        if ($search !== '') {
            $catalog_query->whereRaw("domain LIKE ? ESCAPE '!'", ['%' . $this->escapeLike($search) . '%']);
        }

        foreach ($catalog_query->limit(self::HISTORY_ROW_LIMIT)->get(['domain', 'niche']) as $catalog_domain) {
            $existing = $suggestions[$catalog_domain->domain] ?? $this->emptySuggestion($catalog_domain->domain);
            // The catalog is the source of truth for a niche, an order's own Niche
            // cell is only used when the catalog has none for that domain.
            $existing['niche'] = $catalog_domain->niche ?: $existing['niche'];
            $suggestions[$catalog_domain->domain] = $existing;
        }

        $data = collect($suggestions)
            ->sort(function (array $a, array $b) use ($search) {
                if ($search !== '') {
                    $a_prefix = str_starts_with($a['domain'], $search);
                    $b_prefix = str_starts_with($b['domain'], $search);
                    if ($a_prefix !== $b_prefix) {
                        return $a_prefix ? -1 : 1;
                    }
                }

                return [$b['order_count'], $a['domain']] <=> [$a['order_count'], $b['domain']];
            })
            ->take($limit)
            ->values();

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/admin/link-building-orders/niches
     *
     * Every distinct niche, from the domain catalog and from orders, sorted
     * alphabetically, for the Niche cell dropdown.
     */
    public function niches(): JsonResponse
    {
        $catalog_niches = LinkBuildingDomain::query()
            ->whereNotNull('niche')
            ->distinct()
            ->pluck('niche');

        $order_niches = LinkBuildingOrderPlacement::query()
            ->whereNotNull('niche')
            ->distinct()
            ->pluck('niche');

        $niches_by_key = [];
        foreach ($catalog_niches->concat($order_niches) as $niche) {
            $trimmed = trim((string) $niche);
            $key     = strtolower($trimmed);
            if ($trimmed !== '' && ! isset($niches_by_key[$key])) {
                $niches_by_key[$key] = $trimmed;
            }
        }

        $niches = array_values($niches_by_key);
        usort($niches, 'strcasecmp');

        return response()->json(['data' => $niches]);
    }

    /**
     * Domains used on the most recent orders that match the search, keyed by
     * domain, with how many of those orders used it and the latest order's values.
     *
     * @return array<string, array<string, mixed>>
     */
    private function orderHistoryByDomain(string $search): array
    {
        $query = LinkBuildingOrderPlacement::query()
            ->whereNotNull('partnership')
            ->where('partnership', '<>', '')
            ->orderByDesc('updated_at')
            ->limit(self::HISTORY_ROW_LIMIT);

        if ($search !== '') {
            $query->whereRaw("partnership LIKE ? ESCAPE '!'", ['%' . $this->escapeLike($search) . '%']);
        }

        $history = [];

        foreach ($query->get(['partnership', 'niche', 'dr_lbs', 'current_traffic', 'current_price']) as $placement) {
            $domain = LinkBuildingDomain::normalizeDomain($placement->partnership);

            if ($domain === null || ($search !== '' && ! str_contains($domain, $search))) {
                continue;
            }

            if (! isset($history[$domain])) {
                // Rows come newest first, so the first one seen holds the latest values.
                $history[$domain] = [
                    'domain'      => $domain,
                    'niche'       => $placement->niche ?: null,
                    'dr'          => $placement->dr_lbs ?: null,
                    'traffic'     => $placement->current_traffic ?: null,
                    'price'       => $placement->current_price ?: null,
                    'order_count' => 0,
                ];
            }

            $history[$domain]['order_count']++;
            $history[$domain]['niche'] ??= $placement->niche ?: null;
        }

        return $history;
    }

    /**
     * The part of a typed Partnership that is compared with domains: lowercase, with
     * no protocol, "www." or path, so "https://www.travel" searches for "travel".
     */
    private function normalizeSearch(string $search): string
    {
        $search = strtolower(trim($search));
        $search = preg_replace(['#^[a-z][a-z0-9+.-]*://#', '#^www\.#'], '', $search);

        return preg_split('#[/?\#\s]#', $search)[0] ?? '';
    }

    private function emptySuggestion(string $domain): array
    {
        return [
            'domain'      => $domain,
            'niche'       => null,
            'dr'          => null,
            'traffic'     => null,
            'price'       => null,
            'order_count' => 0,
        ];
    }

    /** Escapes LIKE wildcards using "!" as the escape character, which is portable across drivers. */
    private function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
