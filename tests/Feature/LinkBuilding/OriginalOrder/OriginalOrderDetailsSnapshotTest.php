<?php

namespace Tests\Feature\LinkBuilding\OriginalOrder;

use App\Models\LinkBuildingOrder;
use App\Models\LinkBuildingOrderPlacementRevision;
use App\Models\LinkBuildingOrderSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\LinkBuilding\InteractsWithLinkBuildingHistory;
use Tests\TestCase;

/**
 * Keywords and landing pages of a deferred order are submitted after checkout,
 * by the client or by an admin on their behalf. Each submission keeps another
 * read only copy of the order and is recorded in the edit history.
 */
class OriginalOrderDetailsSnapshotTest extends TestCase
{
    use RefreshDatabase, InteractsWithLinkBuildingHistory;

    private User $client;

    private LinkBuildingOrder $order;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->setUpLinkBuildingHistory();
        $this->client = $this->makeUser('client');
        $this->order  = $this->clientOrder($this->client, [['status' => 'Pending Details']], ['status' => 'pending_details']);
    }

    private function details(string $keyword): array
    {
        return ['placements' => [[
            'id'           => $this->order->items->first()->placements->first()->id,
            'keyword'      => $keyword,
            'landing_page' => 'https://www.getweave.com/dental-front-office-training/',
            'exact_match'  => true,
        ]]];
    }

    public function test_details_submitted_by_the_client_keep_a_copy_and_are_recorded(): void
    {
        $this->actingAs($this->client, 'api')
            ->putJson("/api/link-building/orders/{$this->order->id}/details", $this->details('dental front office training'))
            ->assertOk();

        $snapshot = $this->order->snapshots()->sole();
        $this->assertSame(LinkBuildingOrderSnapshot::TYPE_DETAILS_SUBMITTED, $snapshot->type);
        $this->assertNull($snapshot->payment_method);
        $this->assertSame($this->client->id, $snapshot->captured_by_id);
        $this->assertSame('dental front office training', $snapshot->payload['items'][0]['placements'][0]['keyword']);
        $this->assertTrue($snapshot->payload['items'][0]['placements'][0]['exact_match']);

        $revision = LinkBuildingOrderPlacementRevision::where('source', 'client_details')->where('event', 'updated')->first();
        $this->assertNotNull($revision);
        $this->assertSame($this->client->id, $revision->user_id);
        $this->assertSame('dental front office training', $revision->cell_changes['keyword']['new']);
    }

    public function test_details_filled_in_by_an_admin_keep_a_copy_and_are_recorded(): void
    {
        $this->actingAs($this->admin, 'api')
            ->putJson("/api/admin/orders/{$this->order->id}/link-building-details", $this->details('front office training'))
            ->assertOk();

        $snapshot = $this->order->snapshots()->sole();
        $this->assertSame($this->admin->id, $snapshot->captured_by_id);
        $this->assertTrue(LinkBuildingOrderPlacementRevision::where('source', 'admin_details')->where('user_id', $this->admin->id)->exists());
    }

    public function test_every_submission_keeps_its_own_copy_in_order(): void
    {
        foreach (['first keywords', 'corrected keywords'] as $keyword) {
            $this->actingAs($this->client, 'api')
                ->putJson("/api/link-building/orders/{$this->order->id}/details", $this->details($keyword))
                ->assertOk();
            $this->travel(1)->minutes();
        }

        $this->assertSame(
            ['first keywords', 'corrected keywords'],
            $this->order->snapshots()->get()->map(fn ($s) => $s->payload['items'][0]['placements'][0]['keyword'])->all()
        );
    }

    public function test_a_rejected_submission_keeps_no_copy(): void
    {
        $this->order->update(['status' => 'processing']);

        $this->actingAs($this->client, 'api')
            ->putJson("/api/link-building/orders/{$this->order->id}/details", $this->details('too late'))
            ->assertStatus(422);

        $this->assertSame(0, $this->order->snapshots()->count());
    }
}
