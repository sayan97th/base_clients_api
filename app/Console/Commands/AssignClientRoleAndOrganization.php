<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class AssignClientRoleAndOrganization extends Command
{
    protected $signature = 'users:assign-client-role-and-organization
                            {--dry-run : Preview affected users without making changes}';

    protected $description = 'Assign the client role and default organization to users that have no role and no organization assigned';

    private const CLIENT_ROLE = 'client';

    public function handle(): int
    {
        $this->info('=== Assign Client Role and Default Organization to Users ===');
        $this->newLine();

        $default_organization = Organization::findDefault();

        if (!$default_organization) {
            $this->error('Default organization not found. Run database seeders first (php artisan db:seed).');
            return self::FAILURE;
        }

        $client_role = Role::where('name', self::CLIENT_ROLE)->first();

        if (!$client_role) {
            $this->error('Client role not found. Run database seeders first (php artisan db:seed).');
            return self::FAILURE;
        }

        $this->line("Default organization: <fg=cyan>{$default_organization->name}</> (ID: {$default_organization->id})");
        $this->line("Role to assign: <fg=cyan>{$client_role->display_name}</> ({$client_role->name})");
        $this->newLine();

        $users_without_role_and_org = $this->findUsersWithoutRoleAndOrganization();

        if ($users_without_role_and_org->isEmpty()) {
            $this->info('All users already have a role and organization assigned. Nothing to do.');
            return self::SUCCESS;
        }

        $this->displayAffectedUsers($users_without_role_and_org);

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->warn('Dry-run mode: no changes were made.');
            return self::SUCCESS;
        }

        $this->newLine();

        try {
            $updated_count = $this->assignRoleAndOrganizationToUsers(
                $users_without_role_and_org,
                $client_role,
                $default_organization->id
            );
        } catch (Throwable $e) {
            $this->error('Failed to assign role and organization: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Successfully assigned client role and default organization to {$updated_count} user(s).");

        return self::SUCCESS;
    }

    private function findUsersWithoutRoleAndOrganization()
    {
        return User::whereNull('organization_id')
            ->whereDoesntHave('roles')
            ->get();
    }

    private function displayAffectedUsers($users): void
    {
        $this->line('Users without a role and organization:');
        $this->newLine();

        $rows = $users->map(function (User $user) {
            return [
                $user->id,
                "{$user->first_name} {$user->last_name}",
                $user->email,
            ];
        })->toArray();

        $this->table(['ID', 'Name', 'Email'], $rows);
    }

    private function assignRoleAndOrganizationToUsers($users, Role $client_role, int $organization_id): int
    {
        $updated_count = 0;

        DB::transaction(function () use ($users, $client_role, $organization_id, &$updated_count) {
            foreach ($users as $user) {
                $user->update(['organization_id' => $organization_id]);
                $user->assignRole($client_role->name);
                $this->line("  <fg=green>✓</> Assigned client role and organization to {$user->email}");
                $updated_count++;
            }
        });

        return $updated_count;
    }
}
