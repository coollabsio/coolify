<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

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
    public function handle(): int
    {
        $user = User::find(0);
        if (! $user) {
            $this->error('Root user not found. Create the root account in the browser first.');

            return self::FAILURE;
        }

        $this->info('You are about to change the root user\'s email.');
        $email = strtolower(trim((string) $this->ask('Give me a new email for root user')));

        $validator = Validator::make(['email' => $email], [
            'email' => ['required', 'email', 'max:255', Rule::unique(User::class)->ignore($user->id)],
        ]);
        if ($validator->fails()) {
            $this->error($validator->errors()->first('email'));

            return self::FAILURE;
        }

        $this->info('Updating root email...');
        $user->update(['email' => $email]);
        $this->info('Root user\'s email updated successfully.');

        return self::SUCCESS;
    }
}
