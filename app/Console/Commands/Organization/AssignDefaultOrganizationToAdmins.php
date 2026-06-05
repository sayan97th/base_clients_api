<?php

namespace App\Console\Commands\Organization;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class AssignDefaultOrganizationToAdmins extends Command
{
    protected $signature = 'admin:assign-default-organization
                            {--dry-run : Preview affected users without making changes}';

    protected $description = 'Assign the default organization to admin users (staff, admin, super_admin) that have no organization';

    private const ADMIN_ROLES = ['staff', 'admin', 'super_admin'];

    public function handle(): int
    {
        $this->info('=== Assign Default Organization to Admin Users ===');
        $this->newLine();

        $default_organization = Organization::findDefault();

        if (!$default_organization) {
            $this->error('Default organization not found. Run database seeders first (php artisan db:seed).');
            return self::FAILURE;
        }

        $this->line("Default organization: <fg=cyan>{$default_organization->name}</> (ID: {$default_organization->id})");
        $this->newLine();

        $users_without_org = $this->findAdminUsersWithoutOrganization();

        if ($users_without_org->isEmpty()) {
            $this->info('All admin users already have an organization assigned. Nothing to do.');
            return self::SUCCESS;
        }

        $this->displayAffectedUsers($users_without_org);

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->warn('Dry-run mode: no changes were made.');
            return self::SUCCESS;
        }

        $this->newLine();

        try {
            $updated_count = $this->assignOrganizationToUsers($users_without_org, $default_organization->id);
        } catch (Throwable $e) {
            $this->error('Failed to assign organization: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Successfully assigned default organization to {$updated_count} user(s).");

        return self::SUCCESS;
    }

    private function findAdminUsersWithoutOrganization()
    {
        return User::whereNull('organization_id')
            ->whereHas('roles', function ($query) {
                $query->whereIn('name', self::ADMIN_ROLES);
            })
            ->with('roles')
            ->get();
    }

    private function displayAffectedUsers($users): void
    {
        $this->line('Admin users without an organization:');
        $this->newLine();

        $rows = $users->map(function (User $user) {
            $role_names = $user->roles->pluck('name')->join(', ');

            return [
                $user->id,
                "{$user->first_name} {$user->last_name}",
                $user->email,
                $role_names,
            ];
        })->toArray();

        $this->table(['ID', 'Name', 'Email', 'Roles'], $rows);
    }

    private function assignOrganizationToUsers($users, int $organization_id): int
    {
        $updated_count = 0;

        DB::transaction(function () use ($users, $organization_id, &$updated_count) {
            foreach ($users as $user) {
                $user->update(['organization_id' => $organization_id]);
                $this->line("  <fg=green>✓</> Assigned organization to {$user->email}");
                $updated_count++;
            }
        });

        return $updated_count;
    }
}
