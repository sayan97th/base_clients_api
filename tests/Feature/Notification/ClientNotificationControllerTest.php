<?php

namespace Tests\Feature\Notification;

use App\Models\Role;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HTTP-level coverage for the client notification endpoints. NotificationServiceFilterTest
 * already covers the service's filtering/grouping logic directly; this file exercises the
 * routes themselves, in particular the per-owner authorization boundary every mutating
 * endpoint relies on (a client must never be able to read, mark, archive, or snooze another
 * client's notification just by guessing its id), mirroring AdminNotificationControllerTest's
 * coverage of the same boundary on the admin side.
 */
class ClientNotificationControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $client;
    private User $other_client;
    private NotificationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'client'], ['display_name' => 'Client', 'description' => 'Client user']);

        $this->client = User::factory()->create(['is_active' => true]);
        $this->client->assignRole('client');

        $this->other_client = User::factory()->create(['is_active' => true]);
        $this->other_client->assignRole('client');

        $this->service = app(NotificationService::class);
    }

    public function test_index_only_returns_the_authenticated_users_notifications(): void
    {
        $this->service->createNotification($this->other_client, 'system', 'Not yours.', ['mail_data' => ['skip_email' => true]]);
        $this->service->createNotification($this->client, 'system', 'Belongs to you.', ['mail_data' => ['skip_email' => true]]);

        $response = $this->actingAs($this->client, 'api')
            ->getJson('/api/notifications')
            ->assertOk();

        $messages = collect($response->json('data'))->pluck('message')->all();

        $this->assertSame(['Belongs to you.'], $messages);
    }

    public function test_unread_count_excludes_another_clients_notifications(): void
    {
        $this->service->createNotification($this->other_client, 'system', 'Not yours.', ['mail_data' => ['skip_email' => true]]);

        $response = $this->actingAs($this->client, 'api')
            ->getJson('/api/notifications/unread-count')
            ->assertOk();

        $this->assertSame(0, $response->json('data.unread_count'));
    }

    public function test_store_creates_a_notification_for_the_authenticated_user(): void
    {
        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/notifications', [
                'type'    => 'system',
                'message' => 'A self-created notification.',
            ])
            ->assertCreated();

        $this->assertSame($this->client->id, $response->json('data.user_id'));
    }

    public function test_mark_as_read_succeeds_for_the_owning_client(): void
    {
        $notification = $this->service->createNotification(
            $this->client,
            'system',
            'Belongs to you.',
            ['mail_data' => ['skip_email' => true]]
        );

        $this->actingAs($this->client, 'api')
            ->patchJson("/api/notifications/{$notification->id}/read")
            ->assertOk()
            ->assertJsonPath('data.is_read', true);

        $this->assertTrue($notification->fresh()->is_read);
    }

    public function test_mark_as_read_is_forbidden_for_another_clients_notification(): void
    {
        $notification = $this->service->createNotification(
            $this->other_client,
            'system',
            'Not yours.',
            ['mail_data' => ['skip_email' => true]]
        );

        $this->actingAs($this->client, 'api')
            ->patchJson("/api/notifications/{$notification->id}/read")
            ->assertForbidden();

        $this->assertFalse($notification->fresh()->is_read);
    }

    public function test_mark_all_as_read_only_affects_the_authenticated_users_notifications(): void
    {
        $mine = $this->service->createNotification($this->client, 'system', 'Mine.', ['mail_data' => ['skip_email' => true]]);
        $theirs = $this->service->createNotification($this->other_client, 'system', 'Theirs.', ['mail_data' => ['skip_email' => true]]);

        $response = $this->actingAs($this->client, 'api')
            ->patchJson('/api/notifications/read-all')
            ->assertOk();

        $this->assertSame(1, $response->json('data.updated_count'));
        $this->assertTrue($mine->fresh()->is_read);
        $this->assertFalse($theirs->fresh()->is_read);
    }

    public function test_archive_is_forbidden_for_another_clients_notification(): void
    {
        $notification = $this->service->createNotification(
            $this->other_client,
            'system',
            'Not yours.',
            ['mail_data' => ['skip_email' => true]]
        );

        $this->actingAs($this->client, 'api')
            ->patchJson("/api/notifications/{$notification->id}/archive")
            ->assertForbidden();

        $this->assertFalse($notification->fresh()->is_archived);
    }

    public function test_unarchive_is_forbidden_for_another_clients_notification(): void
    {
        $notification = $this->service->createNotification(
            $this->other_client,
            'system',
            'Not yours.',
            ['mail_data' => ['skip_email' => true]]
        );
        $notification->archive();

        $this->actingAs($this->client, 'api')
            ->patchJson("/api/notifications/{$notification->id}/unarchive")
            ->assertForbidden();

        $this->assertTrue($notification->fresh()->is_archived);
    }

    public function test_snooze_succeeds_for_the_owning_client_and_marks_it_read(): void
    {
        $notification = $this->service->createNotification(
            $this->client,
            'system',
            'Belongs to you.',
            ['mail_data' => ['skip_email' => true]]
        );

        $this->actingAs($this->client, 'api')
            ->patchJson("/api/notifications/{$notification->id}/snooze")
            ->assertOk()
            ->assertJsonPath('data.is_snoozed', true)
            ->assertJsonPath('data.is_read', true);

        $fresh = $notification->fresh();
        $this->assertTrue($fresh->is_snoozed);
        $this->assertNotNull($fresh->snoozed_until);
    }

    public function test_snooze_is_forbidden_for_another_clients_notification(): void
    {
        $notification = $this->service->createNotification(
            $this->other_client,
            'system',
            'Not yours.',
            ['mail_data' => ['skip_email' => true]]
        );

        $this->actingAs($this->client, 'api')
            ->patchJson("/api/notifications/{$notification->id}/snooze")
            ->assertForbidden();

        $this->assertFalse($notification->fresh()->is_snoozed);
    }
}
