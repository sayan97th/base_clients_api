<?php

namespace App\Console\Commands\User;

use App\Models\Team;
use App\Models\User;
use App\Models\UserBan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

class DeleteUser extends Command
{
    protected $signature = 'admin:delete-user';
    protected $description = 'Permanently delete a user account and all associated data (hard delete)';

    public function handle(): int
    {
        $this->info('=== Delete User Account ===');
        $this->newLine();
        $this->warn('WARNING: This action is irreversible. All user data will be permanently deleted.');
        $this->newLine();

        $user = $this->askForUser();
        if ($user === null) {
            return self::FAILURE;
        }

        $this->displayUserInfo($user);

        if (!$this->passesProtectionChecks($user)) {
            return self::FAILURE;
        }

        $this->displayDeletionSummary($user);

        if (!$this->confirmDeletion($user)) {
            $this->warn('Deletion cancelled.');
            return self::SUCCESS;
        }

        return $this->performDeletion($user);
    }

    private function askForUser(): ?User
    {
        $email = $this->ask('Email address of the user to delete');

        $validator = Validator::make(
            ['email' => $email],
            ['email' => 'required|email']
        );

        if ($validator->fails()) {
            $this->error('The email address is not valid.');
            return null;
        }

        $user = User::where('email', $email)
            ->with(['roles', 'organization'])
            ->first();

        if ($user === null) {
            $this->error("No user found with the email [{$email}].");
            return null;
        }

        return $user;
    }

    private function displayUserInfo(User $user): void
    {
        $this->line('User to be deleted:');
        $this->table(
            ['Field', 'Value'],
            [
                ['ID',           $user->id],
                ['Full Name',    $user->first_name . ' ' . $user->last_name],
                ['Email',        $user->email],
                ['Role(s)',      $user->roles->pluck('name')->join(', ') ?: 'None'],
                ['Organization', $user->organization?->name ?? 'None'],
                ['Active',       $user->is_active ? 'Yes' : 'No'],
                ['Registered',   $user->created_at?->toDateTimeString() ?? 'Unknown'],
            ]
        );
        $this->newLine();
    }

    private function passesProtectionChecks(User $user): bool
    {
        if ($user->hasRole('super_admin')) {
            $super_admin_count = User::whereHas('roles', fn ($q) => $q->where('name', 'super_admin'))->count();

            if ($super_admin_count <= 1) {
                $this->error('Cannot delete the last super_admin account. Promote another user to super_admin first.');
                return false;
            }

            $this->warn('This user has the super_admin role. Deleting this account will remove all administrative privileges.');

            if (!$this->confirm('Are you sure you want to delete a super_admin account?', false)) {
                return false;
            }

            $this->newLine();
        }

        return true;
    }

    private function displayDeletionSummary(User $user): void
    {
        $this->line('The following data will be permanently deleted:');
        $this->newLine();

        $rows = $this->buildSummaryRows($user);

        $this->table(['Category', 'Count', 'Note'], $rows);
        $this->newLine();

        $teams_owned = Team::where('created_by', $user->id)->count();
        if ($teams_owned > 0) {
            $this->warn("IMPORTANT: {$teams_owned} team(s) created by this user will also be deleted, including all their members and invitations.");
            $this->newLine();
        }
    }

    private function buildSummaryRows(User $user): array
    {
        $uid = $user->id;

        $orders_count = DB::table('link_building_orders')->where('user_id', $uid)->count()
            + DB::table('new_content_orders')->where('user_id', $uid)->count()
            + DB::table('content_optimization_orders')->where('user_id', $uid)->count()
            + DB::table('content_brief_orders')->where('user_id', $uid)->count()
            + DB::table('premium_mentions_orders')->where('client_id', $uid)->count()
            + DB::table('seo_subscriptions')->where('user_id', $uid)->count();

        return [
            ['Orders',              $orders_count,                                                          'Hard deleted (cascade)'],
            ['Support Tickets',     $user->supportTickets()->count(),                                       'Hard deleted (cascade)'],
            ['Notifications',       $user->notifications()->count(),                                        'Hard deleted (cascade)'],
            ['Credit Transactions', $user->creditTransactions()->count(),                                   'Hard deleted (cascade)'],
            ['Credit Purchases',    $user->creditPurchases()->count(),                                      'Hard deleted (cascade)'],
            ['Teams Created',       Team::where('created_by', $uid)->count(),                               'Hard deleted (cascade)'],
            ['Team Memberships',    $user->teams()->count(),                                                 'Removed (cascade)'],
            ['Payment Profiles',    DB::table('payment_profiles')->where('user_id', $uid)->count(),         'Hard deleted (cascade)'],
            ['Bans Issued',         UserBan::where('banned_by', $uid)->count(),                             'banned_by set to NULL'],
            ['Invitations Sent',    DB::table('invitations')->where('invited_by', $uid)->count()
                                  + DB::table('team_invitations')->where('invited_by', $uid)->count(),      'invited_by set to NULL'],
        ];
    }

    private function confirmDeletion(User $user): bool
    {
        $this->warn('To confirm deletion, type the user\'s email address exactly as shown below:');
        $this->line("  {$user->email}");
        $this->newLine();

        $input = $this->ask('Type the email address to confirm');

        if ($input !== $user->email) {
            $this->error('Email address does not match. Deletion aborted.');
            return false;
        }

        return true;
    }

    private function performDeletion(User $user): int
    {
        $email = $user->email;
        $name  = $user->first_name . ' ' . $user->last_name;

        $this->newLine();
        $this->line("Deleting user [{$email}]...");

        try {
            DB::transaction(function () use ($user) {
                // Nullify bans_by references before deleting to avoid FK constraint violation
                // (user_bans.banned_by has no onDelete cascade in older deployments)
                UserBan::where('banned_by', $user->id)->update(['banned_by' => null]);

                $user->delete();
            });
        } catch (Throwable $e) {
            $this->error('Failed to delete user: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->newLine();
        $this->info("User account [{$email}] ({$name}) has been permanently deleted.");

        return self::SUCCESS;
    }
}
