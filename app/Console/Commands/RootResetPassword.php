<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

use function Laravel\Prompts\password;

class RootResetPassword extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'root:reset-password';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset Root Password';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $user = User::find(0);
        if (! $user) {
            $this->error('Root user not found. Create the root account in the browser first.');

            return self::FAILURE;
        }

        $this->info('You are about to reset the root password.');
        $password = password('Give me a new password for root user: ');
        $passwordAgain = password('Again');
        if ($password != $passwordAgain) {
            $this->error('Passwords do not match.');

            return self::FAILURE;
        }
        $this->info('Updating root password...');
        $user->update(['password' => Hash::make($password)]);
        $this->info('Root password updated successfully.');

        return self::SUCCESS;
    }
}
