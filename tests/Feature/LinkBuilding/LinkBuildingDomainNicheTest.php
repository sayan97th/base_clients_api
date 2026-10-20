<?php

namespace Tests\Feature\LinkBuilding;

use App\Jobs\ProcessLinkBuildingImportJob;
use App\Models\LinkBuildingDomain;
use App\Models\LinkBuildingOrderPlacement;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\LinkBuildingDomainNicheSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Niche of an order's Partnership domain: the domain catalog lookup, the Niche
 * column sync, and the domain picker shown while choosing a Partnership.
 */
class LinkBuildingDomainNicheTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin'],  ['display_name' => 'Admin',  'description' => 'Admin user']);
        Role::firstOrCreate(['name' => 'client'], ['display_name' => 'Client', 'description' => 'Client user']);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
    }

    private function adminPlacement(array $overrides = []): LinkBuildingOrderPlacement
    {
        return LinkBuildingOrderPlacement::create(array_merge([
            'order_id'     => 'BL-' . rand(1000, 9999),
            'client'       => 'Default Client',
            'keyword'      => 'default keyword',
            'landing_page' => 'https://example.com',
            'link_type'    => 'DR 30+ External',
            'status'       => 'New Request',
        ], $overrides));
    }

    // ─── Domain normalization ─────────────────────────────────────────────────

    public static function domainProvider(): array
    {
        return [
            'bare domain'       => ['techvera.com', 'techvera.com'],
            'url with path'     => ['https://www.TechVera.com/blog/post?x=1', 'techvera.com'],
            'http and port'     => ['http://techvera.com:8080', 'techvera.com'],
            'subdomain is kept' => ['https://blog.storymirror.com/read', 'blog.storymirror.com'],
            'not a domain'      => ['n/a', null],
            'blank'             => ['  ', null],
        ];
    }

    #[DataProvider('domainProvider')]
    public function test_normalize_domain(string $value, ?string $expected): void
    {
        $this->assertSame($expected, LinkBuildingDomain::normalizeDomain($value));
    }

    // ─── Niche column sync ────────────────────────────────────────────────────

    public function test_a_new_order_gets_the_niche_of_its_partnership_domain(): void
    {
        LinkBuildingDomain::create(['domain' => 'techvera.com', 'niche' => 'Computer and Software']);

        $placement = $this->adminPlacement(['partnership' => 'https://www.techvera.com/']);

        $this->assertSame('Computer and Software', $placement->fresh()->niche);
    }

    public function test_changing_the_partnership_replaces_the_niche(): void
    {
        LinkBuildingDomain::create(['domain' => 'techvera.com', 'niche' => 'Computer and Software']);
        LinkBuildingDomain::create(['domain' => 'healthblog.com', 'niche' => 'Health and Medicine']);
        $placement = $this->adminPlacement(['partnership' => 'https://techvera.com']);

        $this->actingAs($this->admin, 'api')
            ->putJson("/api/admin/link-building-orders/{$placement->id}", ['partnership' => 'healthblog.com'])
            ->assertOk()
            ->assertJsonPath('data.niche', 'Health and Medicine');
    }

    public function test_changing_the_partnership_to_an_unknown_domain_clears_the_niche(): void
    {
        LinkBuildingDomain::create(['domain' => 'techvera.com', 'niche' => 'Computer and Software']);
        $placement = $this->adminPlacement(['partnership' => 'https://techvera.com']);

        $placement->update(['partnership' => 'https://unknown-domain.com']);

        $this->assertNull($placement->fresh()->niche);
    }

    public function test_setting_a_niche_saves_it_for_the_domain(): void
    {
        $placement = $this->adminPlacement(['partnership' => 'https://newsite.com']);

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/batch-update-cells', [
                'updates' => [['id' => $placement->id, 'fields' => ['niche' => 'Travel and Tourism']]],
            ])
            ->assertOk()
            ->assertJsonPath('data.0.niche', 'Travel and Tourism');

        $this->assertSame('Travel and Tourism', LinkBuildingDomain::where('domain', 'newsite.com')->value('niche'));
        $this->assertSame('Travel and Tourism', $this->adminPlacement(['partnership' => 'newsite.com'])->niche);
    }

    public function test_pasting_a_partnership_and_its_niche_together_keeps_the_pasted_niche(): void
    {
        LinkBuildingDomain::create(['domain' => 'techvera.com', 'niche' => 'Computer and Software']);
        $placement = $this->adminPlacement();

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/batch-update-cells', [
                'updates' => [[
                    'id'     => $placement->id,
                    'fields' => ['partnership' => 'https://techvera.com', 'niche' => 'Business'],
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.0.niche', 'Business');

        $this->assertSame('Business', LinkBuildingDomain::where('domain', 'techvera.com')->value('niche'));
    }

    public function test_clearing_an_order_niche_keeps_the_domain_niche(): void
    {
        LinkBuildingDomain::create(['domain' => 'techvera.com', 'niche' => 'Computer and Software']);
        $placement = $this->adminPlacement(['partnership' => 'https://techvera.com']);

        $placement->update(['niche' => null]);

        $this->assertNull($placement->fresh()->niche);
        $this->assertSame('Computer and Software', LinkBuildingDomain::where('domain', 'techvera.com')->value('niche'));
    }

    public function test_search_matches_the_niche(): void
    {
        LinkBuildingDomain::create(['domain' => 'petsplace.com', 'niche' => 'Pets and Animals']);
        $this->adminPlacement(['order_id' => 'BL-1', 'partnership' => 'https://petsplace.com']);
        $this->adminPlacement(['order_id' => 'BL-2']);

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/link-building-orders/search', ['search' => 'Pets and'])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.order_id', 'BL-1');
    }

    // ─── Domain picker ────────────────────────────────────────────────────────

    public function test_client_role_cannot_list_partnership_domains(): void
    {
        $client = User::factory()->create(['is_active' => true]);
        $client->assignRole('client');

        $this->actingAs($client, 'api')
            ->getJson('/api/admin/link-building-orders/partnership-domains')
            ->assertStatus(403);
    }

    public function test_partnership_domains_combine_the_catalog_and_the_order_history(): void
    {
        LinkBuildingDomain::create(['domain' => 'techvera.com', 'niche' => 'Computer and Software']);
        LinkBuildingDomain::create(['domain' => 'travelnest.com', 'niche' => 'Travel and Tourism']);
        $this->adminPlacement(['partnership' => 'https://techvera.com', 'dr_lbs' => '50', 'current_traffic' => '2880']);
        $this->adminPlacement(['partnership' => 'https://www.techvera.com/post', 'dr_lbs' => '52']);
        $this->adminPlacement(['partnership' => 'https://newblog.net', 'niche' => 'Food']);

        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/link-building-orders/partnership-domains')
            ->assertOk();

        $by_domain = collect($response->json('data'))->keyBy('domain');

        $this->assertSame('Computer and Software', $by_domain['techvera.com']['niche']);
        $this->assertSame(2, $by_domain['techvera.com']['order_count']);
        $this->assertSame('Food', $by_domain['newblog.net']['niche']);
        $this->assertSame(0, $by_domain['travelnest.com']['order_count']);
        // The most used domain comes first.
        $this->assertSame('techvera.com', $response->json('data.0.domain'));
    }

    public function test_partnership_domains_filter_by_search_and_put_prefix_matches_first(): void
    {
        LinkBuildingDomain::create(['domain' => 'mytravelblog.com', 'niche' => 'Travel and Tourism']);
        LinkBuildingDomain::create(['domain' => 'travelnest.com', 'niche' => 'Travel and Tourism']);
        LinkBuildingDomain::create(['domain' => 'techvera.com', 'niche' => 'Computer and Software']);

        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/link-building-orders/partnership-domains?search=https://travel')
            ->assertOk();

        $this->assertSame(['travelnest.com', 'mytravelblog.com'], array_column($response->json('data'), 'domain'));
    }

    public function test_niches_lists_distinct_niches_sorted(): void
    {
        LinkBuildingDomain::create(['domain' => 'a.com', 'niche' => 'Travel']);
        LinkBuildingDomain::create(['domain' => 'b.com', 'niche' => 'business']);
        $this->adminPlacement(['niche' => 'Travel']);
        $this->adminPlacement(['niche' => 'Food']);

        $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/link-building-orders/niches')
            ->assertOk()
            ->assertExactJson(['data' => ['business', 'Food', 'Travel']]);
    }

    // ─── CSV import ───────────────────────────────────────────────────────────

    public function test_csv_import_fills_the_niche_of_each_partnership_domain(): void
    {
        Storage::fake();
        LinkBuildingDomain::create(['domain' => 'techvera.com', 'niche' => 'Computer and Software']);
        Storage::put('imports/niche.csv', implode("\n", [
            'Order ID,Link Type,Client,Keyword,Landing Page,Partnership',
            'BL-7001,DR 30+ External,Acme,kw one,https://acme.com,https://www.techvera.com/post',
            'BL-7002,DR 30+ External,Acme,kw two,https://acme.com,https://unknown-site.com',
        ]));

        (new ProcessLinkBuildingImportJob('niche-import', 'imports/niche.csv', 2, null, null, 'all'))->handle();

        $this->assertSame('Computer and Software', LinkBuildingOrderPlacement::where('order_id', 'BL-7001')->value('niche'));
        $this->assertNull(LinkBuildingOrderPlacement::where('order_id', 'BL-7002')->value('niche'));
    }

    // ─── Seeder ───────────────────────────────────────────────────────────────

    public function test_seeder_loads_the_catalog_and_fills_existing_order_niches_without_overwriting(): void
    {
        LinkBuildingDomain::create(['domain' => 'techvera.com', 'niche' => 'Edited By Admin']);
        $empty_niche   = $this->adminPlacement(['partnership' => 'https://advisorpedia.com']);
        $existing      = $this->adminPlacement(['partnership' => 'https://advisorpedia.com']);
        LinkBuildingOrderPlacement::whereKey($existing->id)->toBase()->update(['niche' => 'Kept']);
        LinkBuildingOrderPlacement::whereKey($empty_niche->id)->toBase()->update(['niche' => null]);

        $this->seed(LinkBuildingDomainNicheSeeder::class);
        $this->seed(LinkBuildingDomainNicheSeeder::class);

        $this->assertGreaterThan(800, LinkBuildingDomain::count());
        $this->assertSame('Edited By Admin', LinkBuildingDomain::where('domain', 'techvera.com')->value('niche'));
        $this->assertSame('IT', $empty_niche->fresh()->niche);
        $this->assertSame('Kept', $existing->fresh()->niche);
    }
}
