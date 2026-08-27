<?php

namespace Tests\Feature\Notification;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The notifications.type DB column enum was widened over several migrations
 * (order_comment, user_registration, invoice), but the FormRequest validation rules
 * lagged behind and had to be kept in sync by hand. These tests pin that sync down.
 */
class NotificationValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $client;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'client'], ['display_name' => 'Client', 'description' => 'Client user']);
        Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin', 'description' => 'Admin']);

        $this->client = User::factory()->create(['is_active' => true]);
        $this->client->assignRole('client');

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
    }

    public static function validClientCreateTypesProvider(): array
    {
        return [
            'payment'           => ['payment'],
            'post'              => ['post'],
            'system'            => ['system'],
            'order'             => ['order'],
            'order_comment'     => ['order_comment'],
            'user_registration' => ['user_registration'],
            'invoice'           => ['invoice'],
            'ticket'            => ['ticket'],
        ];
    }

    public static function validAdminListTypesProvider(): array
    {
        return [
            'order'             => ['order'],
            'payment'           => ['payment'],
            'system'            => ['system'],
            'user_registration' => ['user_registration'],
            'order_comment'     => ['order_comment'],
            'invoice'           => ['invoice'],
            'post'              => ['post'],
            'ticket'            => ['ticket'],
        ];
    }

    /**
     * @dataProvider validClientCreateTypesProvider
     */
    public function test_create_notification_request_accepts_every_currently_valid_type(string $type): void
    {
        $this->actingAs($this->client, 'api')
            ->postJson('/api/notifications', [
                'type'    => $type,
                'message' => 'A test notification.',
            ])
            ->assertCreated();
    }

    public function test_create_notification_request_rejects_an_unknown_type(): void
    {
        $this->actingAs($this->client, 'api')
            ->postJson('/api/notifications', [
                'type'    => 'not_a_real_type',
                'message' => 'A test notification.',
            ])
            ->assertStatus(422);
    }

    public function test_create_notification_request_accepts_optional_deep_link_fields(): void
    {
        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/notifications', [
                'type'          => 'order_comment',
                'message'       => 'A test notification.',
                'resource_type' => 'order_comment',
                'resource_id'   => '123',
                'metadata'      => ['comment_id' => 123],
            ])
            ->assertCreated();

        $response->assertJsonPath('data.resource_type', 'order_comment');
        $response->assertJsonPath('data.resource_id', '123');
        $response->assertJsonPath('data.metadata.comment_id', 123);
    }

    /**
     * @dataProvider validClientCreateTypesProvider
     */
    public function test_list_notifications_request_accepts_every_currently_valid_type_filter(string $type): void
    {
        $this->actingAs($this->client, 'api')
            ->getJson("/api/notifications?type={$type}")
            ->assertOk();
    }

    /**
     * @dataProvider validAdminListTypesProvider
     */
    public function test_list_admin_notifications_request_accepts_every_currently_valid_type_filter(string $type): void
    {
        $this->actingAs($this->admin, 'api')
            ->getJson("/api/admin/notifications?type={$type}")
            ->assertOk();
    }
}
