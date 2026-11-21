<?php

namespace Tests\Feature\Admin\Appointment;

use App\Models\PremiumMentionsAppointment;
use App\Models\ScheduledCallAppointment;
use App\Models\SeoPackage;
use App\Models\SeoPackageAppointment;
use App\Models\SmeAppointment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Admin\InteractsWithAdminRoles;
use Tests\TestCase;

/**
 * Deleting appointments from the admin appointment detail pages. All four
 * pages share the DeleteAppointmentDialog component in the portal.
 *
 * DELETE /api/admin/scheduled-calls/appointments/{id}
 * DELETE /api/admin/sme-content/appointments/{id}
 * DELETE /api/admin/seo-packages/appointments/{id}
 * DELETE /api/admin/premium-mentions/appointments/{id}
 *
 * Deleting only removes the local record. It never calls Calendly, which is
 * why the dialog warns admins to cancel the meeting there first.
 */
class AdminAppointmentDeleteTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithAdminRoles;

    private User $admin;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAdminRoles();
        $this->admin = $this->makeUserWithRole('admin');
        $this->client = $this->makeUserWithRole('client');
    }

    /** @return array<string, array{0: string}> */
    public static function appointmentTypeProvider(): array
    {
        return [
            'scheduled call'   => ['scheduled-calls'],
            'sme'              => ['sme-content'],
            'seo package'      => ['seo-packages'],
            'premium mentions' => ['premium-mentions'],
        ];
    }

    private function makeAppointment(string $type): Model
    {
        $calendly = [
            'event_uri'   => 'https://api.calendly.com/scheduled_events/EVT-' . uniqid(),
            'invitee_uri' => 'https://api.calendly.com/scheduled_events/EVT/invitees/INV-' . uniqid(),
        ];

        return match ($type) {
            'scheduled-calls' => ScheduledCallAppointment::create($calendly + [
                'user_id'      => $this->client->id,
                'status'       => 'pending',
                'scheduled_at' => now()->addDays(2),
            ]),
            'sme-content' => SmeAppointment::create($calendly + [
                'user_id'        => $this->client->id,
                'selected_tiers' => ['expert-interview'],
                'scheduled_at'   => now()->addDays(2),
            ]),
            'seo-packages' => SeoPackageAppointment::create($calendly + [
                'user_id'        => $this->client->id,
                'seo_package_id' => $this->makeSeoPackage()->id,
                'status'         => 'pending',
                'scheduled_at'   => now()->addDays(2),
            ]),
            'premium-mentions' => PremiumMentionsAppointment::create($calendly + [
                'user_id'      => $this->client->id,
                'plan_id'      => 'authority',
                'scheduled_at' => now()->addDays(2),
            ]),
        };
    }

    private function makeSeoPackage(): SeoPackage
    {
        return SeoPackage::firstOrCreate(['id' => 'growth'], [
            'name'            => 'Growth',
            'slug'            => 'growth',
            'price_per_month' => 2500,
            'best_for'        => 'Growing brands',
            'features'        => [],
        ]);
    }

    private function assertAppointmentDeleted(Model $appointment): void
    {
        // Premium Mentions appointments use soft deletes, the rest are removed.
        if ($appointment instanceof PremiumMentionsAppointment) {
            $this->assertSoftDeleted($appointment);

            return;
        }

        $this->assertModelMissing($appointment);
    }

    private function assertAppointmentKept(Model $appointment): void
    {
        $this->assertNotNull($appointment->newQuery()->find($appointment->getKey()));
    }

    #[DataProvider('appointmentTypeProvider')]
    public function test_admin_can_delete_an_appointment(string $type): void
    {
        $appointment = $this->makeAppointment($type);

        $this->deleteAs($this->admin, "/api/admin/{$type}/appointments/{$appointment->id}")->assertSuccessful();

        $this->assertAppointmentDeleted($appointment);
    }

    #[DataProvider('appointmentTypeProvider')]
    public function test_only_the_selected_appointment_is_deleted(string $type): void
    {
        $deleted = $this->makeAppointment($type);
        $kept = $this->makeAppointment($type);

        $this->deleteAs($this->admin, "/api/admin/{$type}/appointments/{$deleted->id}")->assertSuccessful();

        $this->assertAppointmentDeleted($deleted);
        $this->assertAppointmentKept($kept);
    }

    #[DataProvider('appointmentTypeProvider')]
    public function test_deleting_an_appointment_keeps_the_client_account(string $type): void
    {
        $appointment = $this->makeAppointment($type);

        $this->deleteAs($this->admin, "/api/admin/{$type}/appointments/{$appointment->id}")->assertSuccessful();

        $this->assertModelExists($this->client);
    }

    #[DataProvider('appointmentTypeProvider')]
    public function test_staff_can_delete_an_appointment(string $type): void
    {
        $appointment = $this->makeAppointment($type);

        $this->deleteAs($this->makeUserWithRole('staff'), "/api/admin/{$type}/appointments/{$appointment->id}")
            ->assertSuccessful();

        $this->assertAppointmentDeleted($appointment);
    }

    #[DataProvider('appointmentTypeProvider')]
    public function test_deleting_a_missing_appointment_returns_not_found(string $type): void
    {
        $this->deleteAs($this->admin, "/api/admin/{$type}/appointments/999999")
            ->assertNotFound()
            ->assertJson(['message' => 'Appointment not found.']);
    }

    #[DataProvider('appointmentTypeProvider')]
    public function test_client_cannot_delete_an_appointment_from_the_admin_api(string $type): void
    {
        $appointment = $this->makeAppointment($type);

        $this->deleteAs($this->client, "/api/admin/{$type}/appointments/{$appointment->id}")->assertForbidden();

        $this->assertAppointmentKept($appointment);
    }

    #[DataProvider('appointmentTypeProvider')]
    public function test_guest_cannot_delete_an_appointment(string $type): void
    {
        $appointment = $this->makeAppointment($type);

        $this->deleteJson("/api/admin/{$type}/appointments/{$appointment->id}")->assertUnauthorized();

        $this->assertAppointmentKept($appointment);
    }
}
