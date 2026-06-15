<?php

namespace App\Console\Commands\User;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

class PromoteClientToAdmin extends Command
{
    protected $signature = 'admin:promote-client-to-admin
                            {email? : The email address of the client user to promote}';

    protected $description = 'Promote an existing client user to admin role';

    public function handle(): int
    {
        $this->info('=== Promote Client to Admin ===');
        $this->newLine();

        $user = $this->resolveUser();
        if ($user === null) {
            return self::FAILURE;
        }

        $this->displayCurrentInfo($user);

        if (!$this->confirmPromotion($user)) {
            $this->warn('Operation cancelled. No changes were made.');
            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('Promoting user to admin...');

        try {
            DB::transaction(function () use ($user) {
                $this->ensureAdminRoleExists();
                $user->syncRoles(['admin']);
            });
        } catch (Throwable $e) {
            $this->error('Failed to promote user: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('User promoted to admin successfully.');

        $fresh_user = $user->fresh(['roles']);

        $this->table(
            ['Field', 'Value'],
            [
                ['Email', $fresh_user->email],
                ['Name',  "{$fresh_user->first_name} {$fresh_user->last_name}"],
                ['Role',  $fresh_user->roles->pluck('name')->join(', ')],
            ]
        );

        return self::SUCCESS;
    }

    private function resolveUser(): ?User
    {
        $email = $this->argument('email') ?? $this->ask('Email address of the client to promote');

        $validator = Validator::make(
            ['email' => $email],
            ['email' => 'required|email']
        );

        if ($validator->fails()) {
            $this->error('The email address is not valid.');
            return null;
        }

        $user = User::where('email', $email)->with('roles')->first();

        if ($user === null) {
            $this->error("No user found with the email [{$email}].");
            return null;
        }

        $role_names = $user->roles->pluck('name');

        if ($role_names->contains('admin') || $role_names->contains('super_admin')) {
            $this->error("The user [{$email}] already has an admin role ({$role_names->join(', ')}).");
            return null;
        }

        if (!$role_names->contains('client')) {
            $this->warn("The user [{$email}] does not have the client role (current roles: " . ($role_names->join(', ') ?: 'none') . ').');

            if (!$this->confirm('Do you still want to promote this user to admin?', false)) {
                return null;
            }
        }

        return $user;
    }

    private function displayCurrentInfo(User $user): void
    {
        $this->line('Current user information:');
        $this->table(
            ['Field', 'Current Value'],
            [
                ['Email',     $user->email],
                ['Name',      "{$user->first_name} {$user->last_name}"],
                ['Role',      $user->roles->pluck('name')->join(', ') ?: 'None'],
                ['Is Active', $user->is_active ? 'Yes' : 'No'],
            ]
        );
        $this->newLine();
    }

    private function confirmPromotion(User $user): bool
    {
        return $this->confirm(
            "Promote [{$user->email}] to admin? This will replace their current role(s).",
            false
        );
    }

    private function ensureAdminRoleExists(): void
    {
        Role::firstOrCreate(
            ['name' => 'admin'],
            ['display_name' => 'Administrator', 'description' => 'Organization administrator']
        );
    }
}
