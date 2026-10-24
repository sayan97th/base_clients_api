<?php

namespace Tests\Feature\LinkBuilding\History;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\LinkBuilding\InteractsWithLinkBuildingHistory;
use Tests\TestCase;

/**
 * Reading the edit history: the Version history list of actions, the revisions
 * of one action, and the edit history of one row. Every list pages with a cursor.
 */
class HistoryListTest extends TestCase
{
    use RefreshDatabase, InteractsWithLinkBuildingHistory;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->setUpLinkBuildingHistory();
    }

    private function getHistory(string $query = '')
    {
        return $this->actingAs($this->admin, 'api')->getJson('/api/admin/link-building-orders/history' . $query)->assertOk();
    }

    // ─── Version history list ─────────────────────────────────────────────────

    public function test_the_list_shows_each_action_as_a_summary_newest_first(): void
    {
        $placement = $this->placement(['order_id' => 'BL-25730']);
        $this->pasteOver([$placement->id => ['client' => 'Mather', 'keyword' => 'x']]);
        $this->pasteOver([$placement->id => ['keyword' => 'y']], source: 'edit');

        $this->getHistory()
            ->assertJsonPath('data.0.source', 'edit')
            ->assertJsonPath('data.1.source', 'paste')
            ->assertJsonPath('data.1.cells_count', 2)
            ->assertJsonPath('data.1.rows_count', 1)
            ->assertJsonPath('data.1.order_ids_preview', ['BL-25730'])
            ->assertJsonPath('data.1.user.name', 'Krista B')
            ->assertJsonPath('data.1.restored', null)
            ->assertJsonMissingPath('data.0.revisions');
    }

    public function test_a_restored_action_says_when_and_by_whom(): void
    {
        $placement = $this->placement();
        $this->pasteOver([$placement->id => ['keyword' => 'x']]);
        $paste_batch_id = $this->lastBatchId();
        $this->restore(['batch_id' => $paste_batch_id]);

        $this->getHistory('?source=paste')
            ->assertJsonPath('data.0.batch_id', $paste_batch_id)
            ->assertJsonPath('data.0.restored.user.id', $this->admin->id)
            ->assertJsonPath('data.0.restored.restored_at', fn (string $at) => str_ends_with($at, '+00:00'));

        $this->getHistory('?source=restore')
            ->assertJsonPath('data.0.restored_batch_id', $paste_batch_id);
    }

    public function test_the_list_pages_with_a_cursor(): void
    {
        $placement = $this->placement();
        foreach (['a', 'b', 'c'] as $keyword) {
            $this->pasteOver([$placement->id => ['keyword' => $keyword]]);
        }

        $first_page = $this->getHistory('?source=paste&per_page=2')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.has_more', true);

        $this->getHistory('?source=paste&per_page=2&before_id=' . $first_page->json('meta.next_before_id'))
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.has_more', false)
            ->assertJsonPath('meta.next_before_id', null);
    }

    public static function orderIdSearchProvider(): array
    {
        return [
            'full order id'     => ['BL-25730'],
            'start of the id'   => ['BL-2573'],
            'number only'       => ['2573'],
            'lowercase, spaced' => ['  bl-25730 '],
        ];
    }

    #[DataProvider('orderIdSearchProvider')]
    public function test_the_list_finds_actions_by_the_start_of_an_order_id(string $search): void
    {
        $weave  = $this->placement(['order_id' => 'BL-25730']);
        $mather = $this->placement(['order_id' => 'BL-31000']);
        $this->pasteOver([$weave->id => ['keyword' => 'x']]);
        $this->pasteOver([$mather->id => ['keyword' => 'y']]);

        $this->getHistory('?source=paste&order_id=' . urlencode($search))
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.order_ids_preview.0', 'BL-25730');
    }

    public function test_an_order_id_search_treats_wildcards_as_text(): void
    {
        $placement = $this->placement(['order_id' => 'BL-25730']);
        $this->pasteOver([$placement->id => ['keyword' => 'x']]);

        $this->getHistory('?order_id=' . urlencode('%'))->assertJsonCount(0, 'data');
    }

    public function test_the_list_filters_by_who_made_the_change(): void
    {
        $placement = $this->placement();
        $this->pasteOver([$placement->id => ['keyword' => 'by krista']]);

        $other = $this->makeUser('admin');
        $this->actingAs($other, 'api')
            ->putJson("/api/admin/link-building-orders/{$placement->id}", ['keyword' => 'by other'])
            ->assertOk();

        $this->getHistory("?user_id={$other->id}")
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user.id', $other->id);
    }

    public function test_an_unknown_change_type_filter_is_rejected(): void
    {
        $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/link-building-orders/history?source=everything')
            ->assertStatus(422);
    }

    // ─── One action ───────────────────────────────────────────────────────────

    public function test_the_revisions_of_an_action_load_a_page_at_a_time(): void
    {
        $updates = [];
        foreach (range(1, 3) as $i) {
            $updates[$this->placement()->id] = ['keyword' => "pasted {$i}"];
        }
        $this->pasteOver($updates);
        $url = '/api/admin/link-building-orders/history/batches/' . $this->lastBatchId();

        $first_page = $this->actingAs($this->admin, 'api')->getJson("{$url}?per_page=2")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.changes.0.field', 'keyword')
            ->assertJsonPath('data.0.changes.0.new', 'pasted 1')
            ->assertJsonPath('meta.has_more', true);

        $this->actingAs($this->admin, 'api')->getJson("{$url}?per_page=2&after_id=" . $first_page->json('meta.next_after_id'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.changes.0.new', 'pasted 3')
            ->assertJsonPath('meta.has_more', false);
    }

    public function test_an_unknown_action_is_not_found(): void
    {
        $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/link-building-orders/history/batches/missing')
            ->assertNotFound();
    }

    // ─── One row ──────────────────────────────────────────────────────────────

    public function test_row_history_lists_the_changes_of_one_row_newest_first(): void
    {
        $placement = $this->placement(['keyword' => 'v1']);
        $this->pasteOver([$placement->id => ['keyword' => 'v2']]);
        $this->pasteOver([$placement->id => ['keyword' => 'v3']]);

        $this->actingAs($this->admin, 'api')
            ->getJson("/api/admin/link-building-orders/{$placement->id}/history")
            ->assertOk()
            ->assertJsonPath('order_id', $placement->order_id)
            ->assertJsonPath('is_deleted', false)
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.changes.0.old', 'v2')
            ->assertJsonPath('data.0.changes.0.new', 'v3')
            ->assertJsonPath('data.0.user.name', 'Krista B')
            ->assertJsonPath('data.2.event', 'created');
    }

    public function test_row_history_pages_with_a_cursor(): void
    {
        $placement = $this->placement(['keyword' => 'v1']);
        foreach (['v2', 'v3', 'v4'] as $keyword) {
            $this->pasteOver([$placement->id => ['keyword' => $keyword]]);
        }
        $url = "/api/admin/link-building-orders/{$placement->id}/history";

        $first_page = $this->actingAs($this->admin, 'api')->getJson("{$url}?per_page=2")
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.has_more', true);

        $this->actingAs($this->admin, 'api')->getJson("{$url}?per_page=2&before_id=" . $first_page->json('meta.next_before_id'))
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.1.event', 'created')
            ->assertJsonPath('meta.has_more', false);
    }

    public function test_a_deleted_row_keeps_its_history(): void
    {
        $placement = $this->placement(['order_id' => 'BL-25687']);
        $this->actingAs($this->admin, 'api')->deleteJson("/api/admin/link-building-orders/{$placement->id}")->assertOk();

        $this->actingAs($this->admin, 'api')
            ->getJson("/api/admin/link-building-orders/{$placement->id}/history")
            ->assertOk()
            ->assertJsonPath('is_deleted', true)
            ->assertJsonPath('order_id', 'BL-25687')
            ->assertJsonPath('data.0.event', 'deleted');
    }

    // ─── Access ───────────────────────────────────────────────────────────────

    public static function historyEndpointProvider(): array
    {
        return [
            'list'           => ['get', '/api/admin/link-building-orders/history'],
            'one action'     => ['get', '/api/admin/link-building-orders/history/batches/x'],
            'one row'        => ['get', '/api/admin/link-building-orders/x/history'],
            'restore'        => ['post', '/api/admin/link-building-orders/history/restore'],
            'restore status' => ['get', '/api/admin/link-building-orders/history/restores/x'],
        ];
    }

    #[DataProvider('historyEndpointProvider')]
    public function test_guests_cannot_use_the_history(string $method, string $url): void
    {
        $this->json($method, $url)->assertUnauthorized();
    }

    #[DataProvider('historyEndpointProvider')]
    public function test_clients_cannot_use_the_history(string $method, string $url): void
    {
        $this->actingAs($this->makeUser('client'), 'api')->json($method, $url)->assertForbidden();
    }

    public function test_staff_can_read_the_history(): void
    {
        $this->actingAs($this->makeUser('staff'), 'api')
            ->getJson('/api/admin/link-building-orders/history')
            ->assertOk();
    }
}
