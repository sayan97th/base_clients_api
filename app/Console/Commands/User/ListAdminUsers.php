<?php

namespace App\Console\Commands\User;

use App\Models\User;
use Illuminate\Console\Command;

class ListAdminUsers extends Command
{
    protected $signature = 'admin:list-users
                            {--role= : Filter by role (super_admin, admin, owner)}
                            {--search= : Search by email, first name, or last name}';

    protected $description = 'List all admin-side users with their email and name';

    public function handle(): int
    {
        $this->info('=== Admin Users ===');
        $this->newLine();

        $query = User::with('roles')
            ->whereHas('roles', function ($q) {
                $admin_roles = ['super_admin', 'admin', 'owner', 'staff'];

                $role_filter = $this->option('role');
                if ($role_filter) {
                    if (!in_array($role_filter, $admin_roles)) {
                        $this->error("Invalid role. Valid options: super_admin, admin, owner, staff");
                        return;
                    }
                    $q->where('name', $role_filter);
                } else {
                    $q->whereIn('name', $admin_roles);
                }
            });

        $search = $this->option('search');
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('email', 'like', "%{$search}%")
                  ->orWhere('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%");
            });
        }

        $users = $query->orderBy('first_name')->get();

        if ($users->isEmpty()) {
            $this->warn('No admin users found.');
            return self::SUCCESS;
        }

        $rows = $users->map(fn (User $user) => [
            $user->first_name,
            $user->last_name,
            $user->email,
            $user->roles->pluck('name')->implode(', '),
            $user->is_active ? 'Active' : 'Inactive',
        ])->toArray();

        $this->table(
            ['First Name', 'Last Name', 'Email', 'Role(s)', 'Status'],
            $rows
        );

        $this->newLine();
        $this->line("Total: <info>{$users->count()}</info> admin user(s) found.");

        return self::SUCCESS;
    }
}
