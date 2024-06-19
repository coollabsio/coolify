<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class AdminRemoveUser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'admin:remove-user {email}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove User from database';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {
            $email = $this->argument('email');
            $confirm = $this->confirm('Are you sure you want to remove user with email: '.$email.'?');
            if (! $confirm) {
                $this->info('User removal cancelled.');

                return;
            }
            $this->info("Removing user with email: $email");
            $user = User::whereEmail($email)->firstOrFail();
            $teams = $user->teams;
            foreach ($teams as $team) {
                if ($team->members->count() > 1) {
                    $this->error('User is a member of a team with more than one member. Please remove user from team first.');

                    return;
                }
                $team->delete();
            }
            $user->delete();
        } catch (\Exception $e) {
            $this->error('Failed to remove user.');
            $this->error($e->getMessage());

            return;
        }
    }
}
