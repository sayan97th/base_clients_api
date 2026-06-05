<?php

namespace App\Console\Commands\User;

use App\Models\BillingAddress;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

class CreateAdmin extends Command
{
    protected $signature = 'admin:create-admin';
    protected $description = 'Create a new admin user account';

    public function handle(): int
    {
        $this->info('=== Create Admin Account ===');
        $this->newLine();

        $email = $this->askForEmail();
        if ($email === null) {
            return self::FAILURE;
        }

        if ($this->userExists($email)) {
            $this->error("A user with the email [{$email}] already exists.");
            return self::FAILURE;
        }

        $password = $this->askForPassword();
        if ($password === null) {
            return self::FAILURE;
        }

        $first_name = $this->ask('First name');
        $last_name  = $this->ask('Last name');

        $this->newLine();
        $this->line('Creating admin account...');

        try {
            DB::transaction(function () use ($email, $password, $first_name, $last_name) {
                $this->ensureRolesAndPermissionsExist();

                $user = $this->createUser($email, $password, $first_name, $last_name);
                $this->createUserPreference($user);
                $this->createBillingAddress($user);
                $this->assignAdminRole($user);

                if (!$user->organization_id) {
                    $this->warn('Warning: Default organization not found. Run database seeders first (php artisan db:seed).');
                }
            });
        } catch (Throwable $e) {
            $this->error('Failed to create admin: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Admin account created successfully.");
        $organization = Organization::findDefault();

        $this->table(
            ['Field', 'Value'],
            [
                ['Email',        $email],
                ['Name',         "{$first_name} {$last_name}"],
                ['Role',         'admin'],
                ['Organization', $organization?->name ?? 'Not assigned (seed the database first)'],
            ]
        );

        return self::SUCCESS;
    }

    private function askForEmail(): ?string
    {
        $email = $this->ask('Email address');

        $validator = Validator::make(
            ['email' => $email],
            ['email' => 'required|email']
        );

        if ($validator->fails()) {
            $this->error('The email address is not valid.');
            return null;
        }

        return $email;
    }

    private function askForPassword(): ?string
    {
        $password = $this->secret('Password (min 8 characters)');

        if (strlen($password) < 8) {
            $this->error('The password must be at least 8 characters.');
            return null;
        }

        $confirmation = $this->secret('Confirm password');

        if ($password !== $confirmation) {
            $this->error('The passwords do not match.');
            return null;
        }

        return $password;
    }

    private function userExists(string $email): bool
    {
        return User::where('email', $email)->exists();
    }

    private function ensureRolesAndPermissionsExist(): void
    {
        $roles = [
            ['name' => 'super_admin', 'display_name' => 'Super Administrator', 'description' => 'Full system access'],
            ['name' => 'owner',       'display_name' => 'Owner',               'description' => 'Organization owner'],
            ['name' => 'admin',       'display_name' => 'Administrator',        'description' => 'Organization administrator'],
            ['name' => 'user',        'display_name' => 'User',                 'description' => 'Standard user'],
        ];

        foreach ($roles as $role) {
            Role::firstOrCreate(['name' => $role['name']], $role);
        }

        $permissions = [
            ['name' => 'users.view',            'display_name' => 'View Users'],
            ['name' => 'users.create',           'display_name' => 'Create Users'],
            ['name' => 'users.update',           'display_name' => 'Update Users'],
            ['name' => 'users.delete',           'display_name' => 'Delete Users'],
            ['name' => 'roles.view',             'display_name' => 'View Roles'],
            ['name' => 'roles.assign',           'display_name' => 'Assign Roles'],
            ['name' => 'organizations.view',     'display_name' => 'View Organizations'],
            ['name' => 'organizations.create',   'display_name' => 'Create Organizations'],
            ['name' => 'organizations.update',   'display_name' => 'Update Organizations'],
            ['name' => 'organizations.delete',   'display_name' => 'Delete Organizations'],
            ['name' => 'clients.view',           'display_name' => 'View Clients'],
            ['name' => 'clients.create',         'display_name' => 'Create Clients'],
            ['name' => 'clients.update',         'display_name' => 'Update Clients'],
            ['name' => 'clients.delete',         'display_name' => 'Delete Clients'],
            ['name' => 'teams.view',             'display_name' => 'View Teams'],
            ['name' => 'teams.create',           'display_name' => 'Create Teams'],
            ['name' => 'teams.update',           'display_name' => 'Update Teams'],
            ['name' => 'teams.delete',           'display_name' => 'Delete Teams'],
            ['name' => 'teams.invite',           'display_name' => 'Invite Team Members'],
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission['name']], $permission);
        }

        $admin_permissions = [
            'users.view', 'users.create', 'users.update',
            'roles.view',
            'organizations.view',
            'clients.view', 'clients.create', 'clients.update',
            'teams.view', 'teams.create', 'teams.update', 'teams.invite',
        ];

        $admin = Role::where('name', 'admin')->first();
        $admin->permissions()->syncWithoutDetaching(
            Permission::whereIn('name', $admin_permissions)->pluck('id')
        );
    }

    private function createUser(string $email, string $password, string $first_name, string $last_name): User
    {
        $default_organization = Organization::findDefault();

        return User::create([
            'first_name'        => $first_name,
            'last_name'         => $last_name,
            'email'             => $email,
            'business_email'    => $email,
            'password'          => $password,
            'email_verified_at' => now(),
            'organization_id'   => $default_organization?->id,
        ]);
    }

    private function createUserPreference(User $user): void
    {
        UserPreference::create([
            'user_id'  => $user->id,
            'timezone' => 'UTC',
            'language' => 'en',
        ]);
    }

    private function createBillingAddress(User $user): void
    {
        BillingAddress::create([
            'user_id'       => $user->id,
            'billing_email' => $user->email,
        ]);
    }

    private function assignAdminRole(User $user): void
    {
        $user->syncRoles(['admin']);
    }
}
