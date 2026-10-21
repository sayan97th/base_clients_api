<?php

namespace Tests\Feature\LinkBuilding;

use App\Models\LinkBuildingOrderPlacement;
use App\Models\LinkBuildingPartnerMetric;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
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

    public function test_exported_partner_database_headers_are_recognised(): void
    {
        $placement = $this->placement();

        $status = $this->importMetrics(
            "Website,Ahrefs Domain Rating,Ahrefs Organic Traffic,Publisher,Posting Fee,Notes\n"
            . "techkaya.com,52,1800,David,20,ignored column\n"
        );

        $this->assertSame(['dr', 'traffic', 'current_poc', 'current_price'], $status['reference_columns']);
        $fresh = $placement->fresh();
        $this->assertSame('52', $fresh->dr_formula);
        $this->assertSame('1800', $fresh->current_traffic);
        $this->assertSame('David', $fresh->current_poc);
        $this->assertSame('20', $fresh->current_price);
        $this->assertSame('do-not-touch', $fresh->notes);
    }

    public function test_the_leftmost_matching_column_is_used(): void
    {
        $this->importMetrics("Partnership,DR,DR Formula\ntechkaya.com,74,99\n");

        $this->assertSame('74', LinkBuildingPartnerMetric::where('domain_key', 'techkaya.com')->value('dr'));
    }

    public function test_file_with_a_utf8_bom_is_read(): void
    {
        $placement = $this->placement();

        $status = $this->importMetrics("\xEF\xBB\xBFPartnership,DR\ntechkaya.com,61\n", ['target_columns' => ['dr_formula']]);

        $this->assertSame('completed', $status['status']);
        $this->assertSame('61', $placement->fresh()->dr_formula);
    }

    public function test_a_domain_listed_twice_keeps_its_last_row(): void
    {
        $status = $this->importMetrics("Partnership,DR\ntechkaya.com,40\nhttps://www.techkaya.com/,45\n");

        $this->assertSame(1, $status['reference_rows']);
        $this->assertSame('45', LinkBuildingPartnerMetric::where('domain_key', 'techkaya.com')->value('dr'));
    }

    public function test_file_rows_without_a_domain_are_ignored(): void
    {
        $status = $this->importMetrics("Partnership,DR\n,40\n   ,41\ntechkaya.com,45\n\n");

        $this->assertSame(1, $status['reference_rows']);
        $this->assertSame(1, LinkBuildingPartnerMetric::count());
    }

    public function test_running_the_same_file_twice_reports_rows_as_already_up_to_date(): void
    {
        $this->placement();
        $csv = "Partnership,DR,Traffic,POC,Price\ntechkaya.com,74,2300,David,20\n";

        $this->importMetrics($csv);
        $status = $this->importMetrics($csv);

        $this->assertSame(0, $status['updated']);
        $this->assertSame(1, $status['unchanged']);
    }

    public function test_rows_without_a_partnership_are_never_touched(): void
    {
        $empty = $this->placement(['partnership' => null]);

        $status = $this->importMetrics("Partnership,DR,Traffic,POC,Price\ntechkaya.com,74,2300,David,20\n");

        $this->assertSame(0, $status['total']);
        $this->assertSame('stale-poc', $empty->fresh()->current_poc);
    }

    public function test_rows_after_the_end_date_keep_their_values(): void
    {
        $future = $this->placement(['request_date' => now()->addMonth()->format('m/d/Y')]);

        $status = $this->importMetrics("Partnership,POC,Price\ntechkaya.com,David,20\n", [
            'date_to' => now()->format('m/d/Y'),
        ]);

        $this->assertSame(1, $status['out_of_range']);
        $this->assertSame('stale-poc', $future->fresh()->current_poc);
    }

    public function test_default_window_covers_the_last_two_months(): void
    {
        $recent = $this->placement(['request_date' => now()->subMonths(2)->addDay()->format('m/d/Y')]);
        $old    = $this->placement(['request_date' => now()->subMonths(2)->subDays(2)->format('m/d/Y')]);

        $this->importMetrics("Partnership,POC,Price\ntechkaya.com,David,20\n");

        $this->assertSame('David', $recent->fresh()->current_poc);
        $this->assertSame('stale-poc', $old->fresh()->current_poc);
    }

    public function test_rows_without_a_request_date_are_updated(): void
    {
        $undated = $this->placement(['request_date' => null]);

        $this->importMetrics("Partnership,POC,Price\ntechkaya.com,David,20\n");

        $this->assertSame('David', $undated->fresh()->current_poc);
    }

    public function test_metrics_only_first_upload_never_flags_rows_as_site_removed(): void
    {
        $placement = $this->placement(['partnership' => 'https://not-in-file.com']);

        $status = $this->importMetrics("Partnership,DR,Traffic\ntechkaya.com,74,2300\n");

        $this->assertSame(0, $status['site_removed']);
        $this->assertSame('stale-poc', $placement->fresh()->current_poc);
    }

    public function test_domain_without_metrics_keeps_its_dr_and_is_listed_for_review(): void
    {
        $placement = $this->placement(['order_id' => 'BL-7001', 'partnership' => 'https://no-metrics.com']);

        $status = $this->importMetrics("Partnership,DR,Traffic,POC,Price\ntechkaya.com,74,2300,David,20\nno-metrics.com,,,Areeba,15\n");

        $this->assertSame('stale-dr', $placement->fresh()->dr_formula);
        $this->assertSame('Areeba', $placement->fresh()->current_poc);
        $this->assertSame('BL-7001', $status['skipped_records'][0]['order_id']);
        $this->assertStringContainsString('no-metrics.com', $status['skipped_records'][0]['reason']);
    }

    public function test_blank_metric_cells_keep_the_metrics_saved_before(): void
    {
        $placement = $this->placement();

        $this->importMetrics("Partnership,DR,Traffic\ntechkaya.com,74,2300\n");
        $this->importMetrics("Partnership,DR,Traffic\ntechkaya.com,,\n");

        $reference = LinkBuildingPartnerMetric::where('domain_key', 'techkaya.com')->first();
        $this->assertSame('74', $reference->dr);
        $this->assertSame('2300', $reference->traffic);
        $this->assertSame('74', $placement->fresh()->dr_formula);
    }

    public function test_site_that_returns_to_the_partner_list_is_restored(): void
    {
        $placement = $this->placement();

        $this->importMetrics("Partnership,POC,Price\nother.com,Muhammad,80\n");
        $this->assertSame(LinkBuildingPartnerMetric::SITE_REMOVED, $placement->fresh()->current_poc);

        $this->importMetrics("Partnership,POC,Price\ntechkaya.com,David,20\n");
        $this->assertSame('David', $placement->fresh()->current_poc);
    }

    public function test_uploaded_file_is_deleted_after_the_update(): void
    {
        $this->importMetrics("Partnership,DR\ntechkaya.com,74\n");

        $this->assertSame([], Storage::disk('local')->allFiles('imports/link-building'));
    }

    public function test_uploaded_file_is_deleted_when_the_update_fails(): void
    {
        $status = $this->importMetrics("Order ID,DR\nBL-1,50\n");

        $this->assertSame('failed', $status['status']);
        $this->assertSame([], Storage::disk('local')->allFiles('imports/link-building'));
    }

    public function test_file_without_value_columns_fails(): void
    {
        $status = $this->importMetrics("Partnership,Notes\ntechkaya.com,hello\n");

        $this->assertSame('failed', $status['status']);
        $this->assertStringContainsString('at least one of these columns', $status['errors'][0]['message']);
    }

    public function test_file_with_headers_only_fails(): void
    {
        $status = $this->importMetrics("Partnership,DR\n");

        $this->assertSame('failed', $status['status']);
        $this->assertStringContainsString('no rows', $status['errors'][0]['message']);
    }

    public function test_long_values_are_cut_to_the_column_length(): void
    {
        $this->importMetrics('Partnership,POC' . "\n" . 'techkaya.com,' . str_repeat('x', 400) . "\n");

        $this->assertSame(255, mb_strlen(LinkBuildingPartnerMetric::where('domain_key', 'techkaya.com')->value('current_poc')));
    }

    public function test_rejects_requests_without_a_file_or_columns(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/admin/link-building-orders/partnership-metrics-import', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file', 'target_columns']);
    }

    public function test_rejects_dates_that_are_not_mm_dd_yyyy(): void
    {
        $this->actingAs($this->admin)->postJson('/api/admin/link-building-orders/partnership-metrics-import', [
            'file'           => UploadedFile::fake()->createWithContent('metrics.csv', "Partnership,DR\na.com,1\n"),
            'target_columns' => ['dr_formula'],
            'date_from'      => '2026-10-01',
        ])->assertStatus(422)->assertJsonValidationErrors(['date_from']);
    }

    public function test_rejects_files_that_are_not_csv(): void
    {
        $this->actingAs($this->admin)->postJson('/api/admin/link-building-orders/partnership-metrics-import', [
            'file'           => UploadedFile::fake()->create('metrics.pdf', 10, 'application/pdf'),
            'target_columns' => ['dr_formula'],
        ])->assertStatus(422)->assertJsonValidationErrors(['file']);
    }

    public function test_summary_of_an_empty_reference_list(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/admin/link-building-orders/partnership-metrics/summary')
            ->assertOk()
            ->assertExactJson(['data' => [
                'total_domains'     => 0,
                'metrics_domains'   => 0,
                'listed_partners'   => 0,
                'last_metrics_sync' => null,
                'last_partner_sync' => null,
            ]]);
    }

    public function test_summary_reports_the_last_sync_dates(): void
    {
        Carbon::setTestNow('2026-10-01 09:00:00');
        $this->importMetrics("Partnership,DR\na.com,10\n");
        Carbon::setTestNow('2026-10-15 09:00:00');
        $this->importMetrics("Partnership,POC\na.com,David\n");
        Carbon::setTestNow();

        $response = $this->actingAs($this->admin)->getJson('/api/admin/link-building-orders/partnership-metrics/summary');

        $this->assertStringStartsWith('2026-10-01', $response->json('data.last_metrics_sync'));
        $this->assertStringStartsWith('2026-10-15', $response->json('data.last_partner_sync'));
    }

    public function test_guests_cannot_use_the_partnership_metrics_endpoints(): void
    {
        $this->postJson('/api/admin/link-building-orders/partnership-metrics-import', [])->assertStatus(401);
        $this->getJson('/api/admin/link-building-orders/partnership-metrics/summary')->assertStatus(401);
    }
}
