<?php

namespace App\Console\Commands\Cloud;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class CleanupUnverifiedUsers extends Command
{
    protected $signature = 'cloud:cleanup-unverified-users
                            {--yes : Delete eligible users instead of running a dry run}';

    protected $description = 'Delete unverified users without Stripe subscriptions or defined resources';

    public function handle(): int
    {
        if (! isCloud()) {
            $this->error('This command can only be run on Coolify Cloud.');

            return self::FAILURE;
        }

        $eligibleUsers = $this->eligibleUsers();
        $eligibleCount = $eligibleUsers->count();

        $this->info("Found {$eligibleCount} ".Str::plural('unverified user', $eligibleCount).' eligible for deletion.');
        $shouldDelete = (bool) $this->option('yes');

        if (! $shouldDelete) {
            $this->warn('Dry run only. Use --yes to delete eligible users.');
        }

        $deletedCount = 0;

        if ($eligibleCount > 0) {
            $progressAction = $shouldDelete ? 'Deleting' : 'Checking';
            $progressBar = $this->output->createProgressBar($eligibleCount);
            $progressBar->setFormat("{$progressAction} eligible users: %current%/%max% [%bar%] %percent:3s%%");
            $progressBar->start();

            foreach ($eligibleUsers->lazyById(100) as $user) {
                if ($shouldDelete && $user->delete()) {
                    $deletedCount++;
                }

                $progressBar->advance();
            }

            $progressBar->finish();
            $this->newLine(2);
        }

        if ($shouldDelete) {
            $this->info("Deleted {$deletedCount} ".Str::plural('unverified user', $deletedCount).'.');
        }

        return self::SUCCESS;
    }

    private function eligibleUsers(): Builder
    {
        return User::query()
            ->where('id', '!=', 0)
            ->whereNull('email_verified_at')
            ->whereDoesntHave('teams', fn (Builder $query) => $query->whereKey(0))
            ->whereDoesntHave('teams.subscription')
            ->whereDoesntHave('teams.servers')
            ->whereDoesntHave('teams', function (Builder $query) {
                $query->whereHas('projects.applications')
                    ->orWhereHas('projects.postgresqls')
                    ->orWhereHas('projects.redis')
                    ->orWhereHas('projects.mongodbs')
                    ->orWhereHas('projects.mysqls')
                    ->orWhereHas('projects.mariadbs')
                    ->orWhereHas('projects.keydbs')
                    ->orWhereHas('projects.dragonflies')
                    ->orWhereHas('projects.clickhouses')
                    ->orWhereHas('projects.services');
            });
    }
}
