<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class RootChangeEmail extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'root:change-email';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Change Root Email';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        //
        $this->info('You are about to change the root user\'s email.');
        $email = $this->ask('Give me a new email for root user');
        $this->info('Updating root email...');
        try {
            User::find(0)->update(['email' => $email]);
            $this->info('Root user\'s email updated successfully.');
        } catch (\Exception $e) {
            $this->error('Failed to update root user\'s email.');

            return;
        }
    }
}
