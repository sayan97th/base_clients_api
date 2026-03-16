<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class ChangeUserPassword extends Command
{
    protected $signature = 'admin:change-user-password';
    protected $description = 'Change the password of an existing user account';

    public function handle(): int
    {
        $this->info('=== Change User Password ===');
        $this->newLine();

        $user = $this->askForUser();
        if ($user === null) {
            return self::FAILURE;
        }

        $this->line("User found: {$user->first_name} {$user->last_name} <{$user->email}>");
        $this->newLine();

        $password = $this->askForPassword();
        if ($password === null) {
            return self::FAILURE;
        }

        $this->updatePassword($user, $password);

        $this->newLine();
        $this->info("Password updated successfully for user [{$user->email}].");

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

    private function updatePassword(User $user, string $password): void
    {
        $user->update(['password' => $password]);
    }
}
