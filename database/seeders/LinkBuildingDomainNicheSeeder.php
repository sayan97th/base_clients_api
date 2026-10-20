<?php

namespace Database\Seeders;

use App\Models\LinkBuildingDomain;
use App\Models\LinkBuildingOrderPlacement;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Loads the partnership domain niches from the BASE link sheet's domain databases
 * ("New Express and Organic DB" and "Boxes (Partnerships)", Category column) and
 * fills the Niche of existing orders whose Partnership domain is in that list.
 *
 * Safe to run more than once: a niche an admin already saved for a domain is kept,
 * and only orders with an empty Niche are filled.
 *
 *   php artisan db:seed --class=LinkBuildingDomainNicheSeeder
 */
class LinkBuildingDomainNicheSeeder extends Seeder
{
    private const DATA_FILE = __DIR__ . '/data/link_building_domain_niches.csv';

    public function run(): void
    {
        $niches_by_domain = $this->readNiches();

        $this->seedCatalog($niches_by_domain);
        $filled_count = $this->fillOrderNiches();

        $this->command?->info(count($niches_by_domain) . ' domain niches loaded, ' . $filled_count . ' order niches filled.');
    }

    /** @return array<string, string> niche keyed by normalized domain */
    private function readNiches(): array
    {
        $handle = fopen(self::DATA_FILE, 'r');
        fgetcsv($handle);

        $niches_by_domain = [];
        while (($line = fgetcsv($handle)) !== false) {
            $domain = LinkBuildingDomain::normalizeDomain($line[0] ?? null);
            $niche  = trim((string) ($line[1] ?? ''));

            if ($domain !== null && $niche !== '') {
                $niches_by_domain[$domain] ??= $niche;
            }
        }

        fclose($handle);

        return $niches_by_domain;
    }

    /** Inserts the domains that are not in the catalog yet, and fills empty niches. */
    private function seedCatalog(array $niches_by_domain): void
    {
        $now = now();

        foreach (array_chunk($niches_by_domain, 500, true) as $chunk) {
            LinkBuildingDomain::query()->insertOrIgnore(array_map(
                fn (string $domain, string $niche) => [
                    'id'         => (string) Str::uuid(),
                    'domain'     => $domain,
                    'niche'      => $niche,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                array_keys($chunk),
                $chunk
            ));
        }

        LinkBuildingDomain::query()
            ->whereNull('niche')
            ->whereIn('domain', array_keys($niches_by_domain))
            ->each(function (LinkBuildingDomain $domain) use ($niches_by_domain) {
                $domain->update(['niche' => $niches_by_domain[$domain->domain]]);
            });
    }

    /** Fills the Niche of orders that have a Partnership in the catalog and no Niche yet. */
    private function fillOrderNiches(): int
    {
        $catalog = LinkBuildingDomain::query()->whereNotNull('niche')->pluck('niche', 'domain');
        $filled  = 0;

        LinkBuildingOrderPlacement::query()
            ->whereNotNull('partnership')
            ->where(fn ($q) => $q->whereNull('niche')->orWhere('niche', ''))
            ->select(['id', 'partnership'])
            ->chunkById(500, function ($placements) use ($catalog, &$filled) {
                foreach ($placements as $placement) {
                    $niche = $catalog[LinkBuildingDomain::normalizeDomain($placement->partnership)] ?? null;

                    if ($niche !== null) {
                        // A direct query, so the timestamps and the model events are left untouched.
                        LinkBuildingOrderPlacement::whereKey($placement->id)->toBase()->update(['niche' => $niche]);
                        $filled++;
                    }
                }
            });

        return $filled;
    }
}
