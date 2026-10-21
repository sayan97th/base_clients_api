<?php

namespace Tests\Feature\LinkBuilding;

use App\Models\LinkBuildingOrderPlacement;
use App\Models\LinkBuildingPartnerMetric;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LinkBuildingPartnershipMetricsImportTest extends TestCase
{
    use RefreshDatabase;

    private const ALL_COLUMNS = ['dr_formula', 'current_traffic', 'current_poc', 'current_price'];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super_admin'], ['display_name' => 'Super Admin', 'description' => 'Super admin']);
        Role::firstOrCreate(['name' => 'admin'],       ['display_name' => 'Admin',       'description' => 'Admin user']);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        Storage::fake('local');
    }

    private function placement(array $overrides = []): LinkBuildingOrderPlacement
    {
        return LinkBuildingOrderPlacement::create(array_merge([
            'order_id'        => 'BL-' . rand(10000, 99999),
            'client'          => 'Default Client',
            'keyword'         => 'default keyword',
            'landing_page'    => 'https://example.com',
            'link_type'       => 'DR 30+ External',
            'status'          => 'New Request',
            'request_date'    => now()->format('m/d/Y'),
            'partnership'     => 'https://techkaya.com',
            'current_traffic' => 'stale-traffic',
            'dr_formula'      => 'stale-dr',
            'current_poc'     => 'stale-poc',
            'current_price'   => 'stale-price',
            'notes'           => 'do-not-touch',
        ], $overrides));
    }

    /** Uploads a reference file and returns the final import status. */
    private function importMetrics(string $csv, array $params = []): array
    {
        $response = $this->actingAs($this->admin)->postJson('/api/admin/link-building-orders/partnership-metrics-import', array_merge([
            'file'           => UploadedFile::fake()->createWithContent('metrics.csv', $csv),
            'target_columns' => self::ALL_COLUMNS,
        ], $params));

        $response->assertStatus(202);

        return $this->actingAs($this->admin)
            ->getJson('/api/admin/link-building-orders/import-status/' . $response->json('import_id'))
            ->json();
    }

    public function test_updates_every_order_of_a_partnership_regardless_of_order_id(): void
    {
        $first  = $this->placement(['order_id' => 'BL-1001', 'partnership' => 'https://www.TechKaya.com/']);
        $second = $this->placement(['order_id' => 'BL-1002', 'partnership' => 'techkaya.com']);
        $other  = $this->placement(['order_id' => 'BL-1003', 'partnership' => 'https://other-site.com']);

        $status = $this->importMetrics(
            "Partnership,DR,Traffic,Current POC,Current Price\n"
            . "techkaya.com,74,2300,David,\$20\n"
            . "other-site.com,50,900,Muhammad,\$80\n"
        );

        $this->assertSame('completed', $status['status']);
        $this->assertSame(3, $status['updated']);

        foreach ([$first, $second] as $placement) {
            $fresh = $placement->fresh();
            $this->assertSame('74', $fresh->dr_formula);
            $this->assertSame('2300', $fresh->current_traffic);
            $this->assertSame('David', $fresh->current_poc);
            $this->assertSame('$20', $fresh->current_price);
            $this->assertSame('do-not-touch', $fresh->notes);
        }

        $this->assertSame('Muhammad', $other->fresh()->current_poc);
        $this->assertSame(2, LinkBuildingPartnerMetric::count());
    }

    public function test_partnership_missing_from_the_partner_list_is_marked_site_removed(): void
    {
        $removed = $this->placement(['partnership' => 'https://buddymagazine.org']);

        // An earlier upload had the site in the partner list.
        $this->importMetrics("Domain,Publisher,Posting Fee\nbuddymagazine.org,Arslan,35\ntechkaya.com,David,20\n");
        $this->assertSame('Arslan', $removed->fresh()->current_poc);

        $status = $this->importMetrics("Domain,Publisher,Posting Fee\ntechkaya.com,David,20\n");

        $this->assertSame(1, $status['site_removed']);
        $fresh = $removed->fresh();
        $this->assertSame(LinkBuildingPartnerMetric::SITE_REMOVED, $fresh->current_poc);
        $this->assertSame(LinkBuildingPartnerMetric::SITE_REMOVED, $fresh->current_price);
        // A partner only file never touches DR and traffic.
        $this->assertSame('stale-dr', $fresh->dr_formula);
        $this->assertSame('stale-traffic', $fresh->current_traffic);
    }

    public function test_missing_partnerships_are_kept_when_mark_missing_as_removed_is_off(): void
    {
        $placement = $this->placement(['partnership' => 'https://buddymagazine.org']);

        $this->importMetrics("Domain,POC,Price\nbuddymagazine.org,Arslan,35\n");
        $this->importMetrics("Domain,POC,Price\ntechkaya.com,David,20\n", ['mark_missing_as_removed' => false]);

        $this->assertSame('Arslan', $placement->fresh()->current_poc);
    }

    public function test_metrics_file_keeps_the_poc_of_the_reference_list(): void
    {
        $this->importMetrics("Partnership,POC,Price\ntechkaya.com,David,20\n");
        $this->importMetrics("Partnership,DR,Traffic\ntechkaya.com,61,4100\n");

        $reference = LinkBuildingPartnerMetric::where('domain_key', 'techkaya.com')->first();
        $this->assertSame('David', $reference->current_poc);
        $this->assertSame('61', $reference->dr);
        $this->assertNotNull($reference->partner_synced_at);
    }

    public function test_rows_outside_the_date_range_keep_their_values(): void
    {
        $old = $this->placement(['request_date' => now()->subMonths(4)->format('m/d/Y')]);

        $status = $this->importMetrics("Partnership,DR,Traffic,POC,Price\ntechkaya.com,74,2300,David,20\n", [
            'date_from' => now()->subMonths(2)->format('m/d/Y'),
        ]);

        $this->assertSame(1, $status['out_of_range']);
        $this->assertSame(0, $status['updated']);
        $this->assertSame('stale-poc', $old->fresh()->current_poc);
    }

    public function test_only_the_selected_columns_are_written_and_their_formulas_removed(): void
    {
        $placement = $this->placement([
            'cell_formulas' => [
                'current_traffic' => '=[dr_lbs:~0]*100',
                'final_price'     => '=[current_price:~0]*1.15',
            ],
        ]);

        $this->importMetrics("Partnership,DR,Traffic,POC,Price\ntechkaya.com,74,2300,David,20\n", [
            'target_columns' => ['current_traffic'],
        ]);

        $fresh = $placement->fresh();
        $this->assertSame('2300', $fresh->current_traffic);
        $this->assertSame('stale-dr', $fresh->dr_formula);
        $this->assertSame('stale-poc', $fresh->current_poc);
        $this->assertSame(['final_price' => '=[current_price:~0]*1.15'], $fresh->cell_formulas);
    }

    public function test_file_without_a_partnership_column_fails(): void
    {
        $status = $this->importMetrics("Order ID,DR\nBL-1,50\n");

        $this->assertSame('failed', $status['status']);
        $this->assertStringContainsString('Partnership', $status['errors'][0]['message']);
    }

    public function test_rejects_target_columns_outside_the_allowed_list(): void
    {
        $this->actingAs($this->admin)->postJson('/api/admin/link-building-orders/partnership-metrics-import', [
            'file'           => UploadedFile::fake()->createWithContent('metrics.csv', "Partnership,DR\na.com,1\n"),
            'target_columns' => ['notes'],
        ])->assertStatus(422);
    }

    public function test_changing_the_partnership_of_a_row_looks_up_the_new_site(): void
    {
        $this->importMetrics("Partnership,DR,Traffic,POC,Price\ntechkaya.com,74,2300,David,\$20\nmetricless.com,,,Zaryab,\$15\n");

        $placement = $this->placement(['partnership' => 'https://smiletotalk.com']);

        $response = $this->actingAs($this->admin)->postJson('/api/admin/link-building-orders/batch-update-cells', [
            'updates' => [['id' => $placement->id, 'fields' => ['partnership' => 'techkaya.com']]],
        ]);

        $response->assertOk();
        $this->assertSame('David', $response->json('data.0.current_poc'));
        $this->assertSame('$20', $response->json('data.0.current_price'));
        $this->assertSame('74', $response->json('data.0.dr_formula'));

        // A listed site without metrics clears the old site's DR and traffic.
        $this->actingAs($this->admin)->postJson('/api/admin/link-building-orders/batch-update-cells', [
            'updates' => [['id' => $placement->id, 'fields' => ['partnership' => 'metricless.com']]],
        ])->assertOk();

        $fresh = $placement->fresh();
        $this->assertSame('Zaryab', $fresh->current_poc);
        $this->assertNull($fresh->dr_formula);
        $this->assertNull($fresh->current_traffic);

        // A site that is not in the partner list shows SITE REMOVED.
        $this->actingAs($this->admin)->postJson('/api/admin/link-building-orders/batch-update-cells', [
            'updates' => [['id' => $placement->id, 'fields' => ['partnership' => 'unknown-site.com']]],
        ])->assertOk();

        $this->assertSame(LinkBuildingPartnerMetric::SITE_REMOVED, $placement->fresh()->current_price);
    }

    public function test_pasted_metric_values_win_over_the_lookup(): void
    {
        $this->importMetrics("Partnership,POC,Price\ntechkaya.com,David,20\n");
        $placement = $this->placement(['partnership' => 'https://smiletotalk.com']);

        $this->actingAs($this->admin)->postJson('/api/admin/link-building-orders/batch-update-cells', [
            'updates' => [['id' => $placement->id, 'fields' => ['partnership' => 'techkaya.com', 'current_price' => '99']]],
        ])->assertOk();

        $fresh = $placement->fresh();
        $this->assertSame('David', $fresh->current_poc);
        $this->assertSame('99', $fresh->current_price);
    }

    public function test_editing_a_row_without_changing_the_partnership_keeps_its_metrics(): void
    {
        $this->importMetrics("Partnership,POC,Price\nother.com,David,20\n");
        $placement = $this->placement(['partnership' => 'https://techkaya.com', 'current_poc' => 'Kept']);

        $this->actingAs($this->admin)->postJson('/api/admin/link-building-orders/batch-update-cells', [
            'updates' => [['id' => $placement->id, 'fields' => ['partnership' => 'https://www.techkaya.com/', 'notes' => 'edited']]],
        ])->assertOk();

        $this->assertSame('Kept', $placement->fresh()->current_poc);
    }

    public function test_summary_reports_the_reference_list(): void
    {
        $this->importMetrics("Partnership,DR,Traffic\na.com,10,100\nb.com,20,200\n");
        $this->importMetrics("Partnership,POC,Price\na.com,David,20\n");

        $this->actingAs($this->admin)
            ->getJson('/api/admin/link-building-orders/partnership-metrics/summary')
            ->assertOk()
            ->assertJsonPath('data.total_domains', 2)
            ->assertJsonPath('data.metrics_domains', 2)
            ->assertJsonPath('data.listed_partners', 1);
    }

    public function test_non_admin_role_cannot_import_partnership_metrics(): void
    {
        Role::firstOrCreate(['name' => 'client'], ['display_name' => 'Client', 'description' => 'Client user']);
        $client = User::factory()->create(['is_active' => true]);
        $client->assignRole('client');

        $this->actingAs($client)->postJson('/api/admin/link-building-orders/partnership-metrics-import', [
            'file'           => UploadedFile::fake()->createWithContent('metrics.csv', "Partnership,DR\na.com,1\n"),
            'target_columns' => ['dr_formula'],
        ])->assertStatus(403);
    }
}
