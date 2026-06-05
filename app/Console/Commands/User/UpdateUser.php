<?php

namespace App\Console\Commands\User;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

class UpdateUser extends Command
{
    protected $signature = 'admin:update-user';
    protected $description = 'Update an existing user account (name, email, password, role)';

    public function handle(): int
    {
        $this->info('=== Update User Account ===');
        $this->newLine();

        $user = $this->askForUser();
        if ($user === null) {
            return self::FAILURE;
        }

        $this->displayCurrentInfo($user);

        $updates = $this->gatherUpdates($user);
        if ($updates === null) {
            return self::FAILURE;
        }

        if (empty($updates['fields']) && $updates['role'] === null) {
            $this->warn('No changes selected. Exiting.');
            return self::SUCCESS;
        }

        try {
            DB::transaction(function () use ($user, $updates) {
                if (!empty($updates['fields'])) {
                    $user->update($updates['fields']);
                }

                if ($updates['role'] !== null) {
                    $user->syncRoles([$updates['role']]);
                }
            });
        } catch (Throwable $e) {
            $this->error('Failed to update user: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->newLine();
        $this->info("User account updated successfully.");

        $this->table(
            ['Field', 'Value'],
            [
                ['Email',      $user->fresh()->email],
                ['Name',       $user->fresh()->first_name . ' ' . $user->fresh()->last_name],
                ['Role',       $user->fresh()->roles->pluck('name')->join(', ')],
                ['Is Active',  $user->fresh()->is_active ? 'Yes' : 'No'],
            ]
        );

        return self::SUCCESS;
    }

    private function askForUser(): ?User
    {
        $email = $this->ask('Email address of the user to update');

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

        return $user;
    }

    private function displayCurrentInfo(User $user): void
    {
        $this->line('Current user information:');
        $this->table(
            ['Field', 'Current Value'],
            [
                ['Email',      $user->email],
                ['First Name', $user->first_name],
                ['Last Name',  $user->last_name],
                ['Role',       $user->roles->pluck('name')->join(', ') ?: 'None'],
                ['Is Active',  $user->is_active ? 'Yes' : 'No'],
            ]
        );
        $this->newLine();
    }

    private function gatherUpdates(User $user): ?array
    {
        $fields = [];
        $role   = null;

        if ($this->confirm('Update first name?', false)) {
            $first_name = $this->ask('New first name', $user->first_name);
            if (!empty(trim($first_name))) {
                $fields['first_name'] = trim($first_name);
            }
        }

        if ($this->confirm('Update last name?', false)) {
            $last_name = $this->ask('New last name', $user->last_name);
            if (!empty(trim($last_name))) {
                $fields['last_name'] = trim($last_name);
            }
        }

        if ($this->confirm('Update email address?', false)) {
            $new_email = $this->askForNewEmail($user->email);
            if ($new_email === null) {
                return null;
            }
            $fields['email']          = $new_email;
            $fields['business_email'] = $new_email;
        }

        if ($this->confirm('Update password?', false)) {
            $password = $this->askForPassword();
            if ($password === null) {
                return null;
            }
            $fields['password'] = $password;
        }

        if ($this->confirm('Update role?', false)) {
            $role = $this->askForRole();
            if ($role === null) {
                return null;
            }
        }

        if ($this->confirm('Update account active status?', false)) {
            $is_active          = $this->confirm('Should the account be active?', $user->is_active);
            $fields['is_active'] = $is_active;
        }

        return ['fields' => $fields, 'role' => $role];
    }

    private function askForNewEmail(string $current_email): ?string
    {
        $email = $this->ask('New email address', $current_email);

        $validator = Validator::make(
            ['email' => $email],
            ['email' => 'required|email']
        );

        if ($validator->fails()) {
            $this->error('The email address is not valid.');
            return null;
        }

        if ($email !== $current_email && User::where('email', $email)->exists()) {
            $this->error("A user with the email [{$email}] already exists.");
            return null;
        }

        return $email;
    }

    private function askForPassword(): ?string
    {
        $password = $this->secret('New password (min 8 characters)');

        if (strlen($password) < 8) {
            $this->error('The password must be at least 8 characters.');
            return null;
        }

        $confirmation = $this->secret('Confirm new password');

        if ($password !== $confirmation) {
            $this->error('The passwords do not match.');
            return null;
        }

        return $password;
    }

    private function askForRole(): ?string
    {
        $available_roles = Role::pluck('name')->toArray();

        if (empty($available_roles)) {
            $this->error('No roles found in the database. Run seeders first.');
            return null;
        }

        $role = $this->choice('Select the new role', $available_roles);

        return $role;
    }
}
