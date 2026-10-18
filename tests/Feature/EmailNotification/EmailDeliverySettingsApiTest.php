<?php

namespace Tests\Feature\EmailNotification;

use App\Models\EmailSuppressionLog;
use App\Models\EmailTypeSetting;
use App\Services\EmailDeliveryService;
use App\Support\Email\EmailTypeCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\EmailNotification\Concerns\InteractsWithEmailDelivery;
use Tests\TestCase;

/**
 * Request and response contract of the Email Types and User Preferences
 * endpoints consumed by the admin portal.
 */
class EmailDeliverySettingsApiTest extends TestCase
{
    use RefreshDatabase, InteractsWithEmailDelivery;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpEmailDelivery();
    }

    // ─── Email Types ─────────────────────────────────────────────────────────

    public function test_email_types_index_returns_every_catalog_entry_and_category(): void
    {
        $admin = $this->makeUserWithRole('admin');

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/email-type-settings')
            ->assertOk()
            ->assertJsonStructure([
                'categories'  => [['key', 'label']],
                'email_types' => [['key', 'label', 'description', 'audience', 'category', 'is_mandatory', 'is_enabled']],
            ]);

        $this->assertSame(EmailTypeCatalog::keys(), array_column($response->json('email_types'), 'key'));
        $this->assertSame(array_keys(EmailTypeCatalog::CATEGORIES), array_column($response->json('categories'), 'key'));
    }

    public function test_mandatory_types_report_as_enabled_even_with_a_stale_disabled_row(): void
    {
        $admin = $this->makeUserWithRole('admin');
        EmailTypeSetting::create(['email_type' => 'order_report', 'is_enabled' => false]);

        $email_types = collect($this->actingAs($admin)->getJson('/api/admin/email-type-settings')->json('email_types'))
            ->keyBy('key');

        $this->assertTrue($email_types['order_report']['is_mandatory']);
        $this->assertTrue($email_types['order_report']['is_enabled']);
    }

    public function test_update_only_touches_the_email_types_sent(): void
    {
        $admin = $this->makeUserWithRole('super_admin');
        EmailTypeSetting::create(['email_type' => 'admin_new_ticket', 'is_enabled' => false]);

        $this->actingAs($admin)
            ->putJson('/api/admin/email-type-settings', [
                'email_types' => [
                    ['key' => 'admin_invoice_paid', 'is_enabled' => false],
                    ['key' => 'admin_credit_purchase', 'is_enabled' => true],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Email type settings updated successfully');

        $this->assertFalse(EmailDeliveryService::isEmailTypeEnabled('admin_invoice_paid'));
        $this->assertTrue(EmailDeliveryService::isEmailTypeEnabled('admin_credit_purchase'));
        $this->assertFalse(EmailDeliveryService::isEmailTypeEnabled('admin_new_ticket'));
        $this->assertSame(3, EmailTypeSetting::count());
    }

    public function test_a_disabled_email_type_can_be_turned_back_on(): void
    {
        $admin = $this->makeUserWithRole('admin');
        $payload = fn (bool $is_enabled) => ['email_types' => [['key' => 'admin_invoice_paid', 'is_enabled' => $is_enabled]]];

        $this->actingAs($admin)->putJson('/api/admin/email-type-settings', $payload(false))->assertOk();
        $this->actingAs($admin)->putJson('/api/admin/email-type-settings', $payload(true))->assertOk();

        $this->assertTrue(EmailDeliveryService::isEmailTypeEnabled('admin_invoice_paid'));
        $this->assertSame(1, EmailTypeSetting::count());
    }

    public function test_update_validates_the_payload(): void
    {
        $admin = $this->makeUserWithRole('admin');

        $this->actingAs($admin)->putJson('/api/admin/email-type-settings', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email_types']);

        $this->actingAs($admin)
            ->putJson('/api/admin/email-type-settings', [
                'email_types' => [
                    ['key' => 'admin_invoice_paid'],
                    ['key' => 'admin_invoice_paid', 'is_enabled' => 'maybe'],
                    ['key' => 'unknown_type', 'is_enabled' => false],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'email_types.0.is_enabled',
                'email_types.1.key',
                'email_types.1.is_enabled',
                'email_types.2.key',
            ]);
    }

    public function test_suppression_logs_return_the_25_most_recent_entries_newest_first(): void
    {
        $staff = $this->makeUserWithRole('staff');

        for ($i = 1; $i <= 30; $i++) {
            EmailSuppressionLog::create([
                'mailable_class'  => 'App\\Mail\\AdminInvoicePaidNotification',
                'email_type'      => 'admin_invoice_paid',
                'recipient_email' => "user{$i}@example.com",
                'reason'          => EmailDeliveryService::REASON_RECIPIENT_MUTED_ALL,
                'suppressed_at'   => now()->subMinutes(30 - $i),
            ]);
        }

        $logs = $this->actingAs($staff)
            ->getJson('/api/admin/email-type-settings/suppression-logs')
            ->assertOk()
            ->json('logs');

        $this->assertCount(25, $logs);
        $this->assertSame('user30@example.com', $logs[0]['recipient_email']);
        $this->assertSame(
            ['mailable_class', 'email_type', 'recipient_email', 'subject', 'reason', 'suppressed_at'],
            array_keys($logs[0])
        );
    }

    public function test_guests_cannot_reach_the_email_settings_endpoints(): void
    {
        $this->getJson('/api/admin/email-type-settings')->assertUnauthorized();
        $this->putJson('/api/admin/email-type-settings', [])->assertUnauthorized();
        $this->getJson('/api/admin/user-email-preferences')->assertUnauthorized();
        $this->putJson('/api/admin/user-email-preferences/1', [])->assertUnauthorized();
        $this->getJson('/api/admin/email-type-settings/suppression-logs')->assertUnauthorized();
    }

    // ─── User Preferences ────────────────────────────────────────────────────

    public function test_preferences_index_returns_each_admins_saved_state(): void
    {
        $admin  = $this->makeUserWithRole('admin', ['first_name' => 'Abby']);
        $staff  = $this->makeUserWithRole('staff', ['first_name' => 'Kennedy']);
        $this->setEmailPreference($staff, false, ['admin_credit_purchase']);

        $users = collect($this->actingAs($admin)->getJson('/api/admin/user-email-preferences')->assertOk()->json('users'))
            ->keyBy('id');

        $this->assertSame(
            ['id', 'first_name', 'last_name', 'email', 'role_display', 'mute_all_emails', 'disabled_email_types'],
            array_keys($users[$staff->id])
        );
        $this->assertSame(['admin_credit_purchase'], $users[$staff->id]['disabled_email_types']);
        $this->assertSame('Staff', $users[$staff->id]['role_display']);
        $this->assertFalse($users[$admin->id]['mute_all_emails']);
        $this->assertSame([], $users[$admin->id]['disabled_email_types']);
    }

    public function test_preferences_index_skips_inactive_admins_and_is_sorted_by_name(): void
    {
        $viewer = $this->makeUserWithRole('admin', ['first_name' => 'Mia', 'last_name' => 'Zed']);
        $this->makeUserWithRole('staff', ['first_name' => 'Aaron', 'last_name' => 'Able']);
        $inactive = $this->makeUserWithRole('staff', ['is_active' => false]);

        $users = $this->actingAs($viewer)->getJson('/api/admin/user-email-preferences')->json('users');

        $this->assertNotContains($inactive->id, array_column($users, 'id'));
        $this->assertSame(['Aaron', 'Mia'], array_column($users, 'first_name'));
    }

    public function test_super_admin_can_update_any_admin_and_duplicates_are_rejected(): void
    {
        $super_admin = $this->makeUserWithRole('super_admin');
        $staff       = $this->makeUserWithRole('staff');

        $this->actingAs($super_admin)
            ->putJson("/api/admin/user-email-preferences/{$staff->id}", [
                'mute_all_emails'      => false,
                'disabled_email_types' => ['admin_new_ticket', 'admin_new_ticket'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['disabled_email_types.0']);

        $this->actingAs($super_admin)
            ->putJson("/api/admin/user-email-preferences/{$staff->id}", [
                'mute_all_emails'      => false,
                'disabled_email_types' => ['admin_new_ticket'],
            ])
            ->assertOk()
            ->assertJsonPath('preference.mute_all_emails', false)
            ->assertJsonPath('preference.disabled_email_types', ['admin_new_ticket']);
    }

    public function test_saving_twice_updates_the_same_preference_row(): void
    {
        $admin = $this->makeUserWithRole('admin');
        $url   = "/api/admin/user-email-preferences/{$admin->id}";

        $this->actingAs($admin)->putJson($url, ['mute_all_emails' => true, 'disabled_email_types' => []])->assertOk();
        $this->actingAs($admin)->putJson($url, ['mute_all_emails' => false, 'disabled_email_types' => ['admin_new_ticket']])->assertOk();

        $this->assertDatabaseCount('user_email_preferences', 1);
        $this->assertDatabaseHas('user_email_preferences', ['user_id' => $admin->id, 'mute_all_emails' => false]);
    }

    public function test_preference_update_validates_required_fields(): void
    {
        $admin = $this->makeUserWithRole('admin');

        $this->actingAs($admin)
            ->putJson("/api/admin/user-email-preferences/{$admin->id}", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mute_all_emails', 'disabled_email_types']);
    }

    public function test_preference_update_returns_404_for_a_missing_user(): void
    {
        $admin = $this->makeUserWithRole('admin');

        $this->actingAs($admin)
            ->putJson('/api/admin/user-email-preferences/999999', ['mute_all_emails' => true, 'disabled_email_types' => []])
            ->assertNotFound();
    }

    public function test_deleting_a_user_removes_their_preferences(): void
    {
        $staff = $this->makeUserWithRole('staff');
        $this->setEmailPreference($staff, true);

        $staff->roles()->detach();
        $staff->delete();

        $this->assertDatabaseMissing('user_email_preferences', ['user_id' => $staff->id]);
    }
}
