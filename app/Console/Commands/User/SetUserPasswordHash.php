<?php

namespace App\Console\Commands\User;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SetUserPasswordHash extends Command
{
    protected $signature = 'admin:set-user-password-hash';
    protected $description = 'Set a pre-generated password hash directly on a user account';

    public function handle(): int
    {
        $this->info('=== Set User Password Hash ===');
        $this->newLine();

        $user = $this->askForUser();
        if ($user === null) {
            return self::FAILURE;
        }

        $this->line("User found: {$user->first_name} {$user->last_name} <{$user->email}>");
        $this->newLine();

        $hash = $this->askForHash();
        if ($hash === null) {
            return self::FAILURE;
        }

        if (!$this->confirm("Are you sure you want to overwrite the password for [{$user->email}]?", false)) {
            $this->warn('Operation cancelled.');
            return self::SUCCESS;
        }

        $this->storeHash($user, $hash);

        $this->newLine();
        $this->info("Password hash updated successfully for user [{$user->email}].");

        return self::SUCCESS;
    }

    private function askForUser(): ?User
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

        $user = User::where('email', $email)->first();

        if ($user === null) {
            $this->error("No user found with the email [{$email}].");
            return null;
        }

        return $user;
    }

    private function askForHash(): ?string
    {
        $hash = $this->ask('Password hash (Laravel bcrypt/argon2 format, e.g. $2y$12$...)');

        if (empty(trim($hash))) {
            $this->error('The hash cannot be empty.');
            return null;
        }

        $hash_info = password_get_info($hash);

        if (!isset($hash_info['algo']) || $hash_info['algo'] === 0 || $hash_info['algo'] === null) {
            $this->error('The provided string is not a valid password hash. Expected a bcrypt ($2y$...) or argon2 ($argon2id$...) hash.');
            return null;
        }

        $this->line("Hash algorithm detected: <comment>{$hash_info['algoName']}</comment>");

        return $hash;
    }

    private function storeHash(User $user, string $hash): void
    {
        // Bypass the Eloquent `hashed` cast so the hash is stored as-is without re-hashing.
        DB::table('users')
            ->where('id', $user->id)
            ->update(['password' => $hash]);
    }
}
