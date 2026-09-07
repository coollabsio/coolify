<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use Illuminate\Console\Command;

class CleanupUnsubscribedServers extends Command
{
    protected $signature = 'cleanup:unsubscribed-servers
                            {--months=6 : Months since subscription ended}
                            {--dry-run : Show what would be deleted without deleting}';

    protected $description = 'Cleanup old unsubscribed teams and all their related data';

    public function handle(): int
    {
        if (! isCloud()) {
            $this->line('This command is only available in cloud mode.');

            return self::SUCCESS;
        }

        $months = (int) $this->option('months');
        $dryRun = $this->option('dry-run');
        $cutoff = now()->subMonths($months);

        $teams = Team::query()
            ->where('id', '!=', 0)
            ->whereHas('subscription', function ($query) use ($cutoff) {
                $query->where('stripe_invoice_paid', false)
                    ->whereNotNull('stripe_customer_id')
                    ->where('updated_at', '<', $cutoff);
            })
            ->with(['subscription', 'servers', 'projects', 'members'])
            ->get();

        if ($teams->isEmpty()) {
            $this->line('No teams found matching cleanup criteria.');

            return self::SUCCESS;
        }

        $totalServers = $teams->sum(fn ($t) => $t->servers->count());
        $totalProjects = $teams->sum(fn ($t) => $t->projects->count());

        // Identify orphaned users: members only in these teams and no other teams
        $teamIds = $teams->pluck('id');
        $allMemberIds = $teams->flatMap(fn ($t) => $t->members->pluck('id'))->unique();
        $orphanedUserIds = $allMemberIds->filter(function ($userId) use ($teamIds) {
            $otherTeamCount = User::find($userId)?->teams()
                ->whereNotIn('teams.id', $teamIds)
                ->count() ?? 0;

            return $otherTeamCount === 0;
        });

        $this->line("Teams to delete: {$teams->count()}");
        $this->line("Servers to delete: {$totalServers}");
        $this->line("Projects to delete: {$totalProjects}");
        $this->line("Orphaned users to delete: {$orphanedUserIds->count()}");
        $this->line("Subscription cutoff: {$cutoff->toDateString()} ({$months} months ago)");

        if ($dryRun) {
            $this->line('');
            $this->line('--- DRY RUN: per-team breakdown ---');
            foreach ($teams as $team) {
                $this->line(sprintf(
                    '  Team #%d "%s" | servers: %d | projects: %d | users: %d | subscription updated: %s',
                    $team->id,
                    $team->name,
                    $team->servers->count(),
                    $team->projects->count(),
                    $team->members->count(),
                    $team->subscription?->updated_at?->toDateString() ?? 'N/A',
                ));
            }

            return self::SUCCESS;
        }

        foreach ($teams as $team) {
            $this->line("Deleting team #{$team->id} \"{$team->name}\"...");

            // 1. Delete all resources on each server first (apps, databases, services)
            foreach ($team->servers as $server) {
                foreach ($server->definedResources() as $resource) {
                    $resource->forceDelete();
                }
            }

            // 2. Force-delete servers (triggers forceDeleting hook: destinations, settings, certs)
            foreach ($team->servers as $server) {
                $server->forceDelete();
            }

            // 3. Delete projects (triggers deleting hook: environments, settings, env vars)
            foreach ($team->projects as $project) {
                $project->delete();
            }

            // 4. Delete team (triggers deleting hook: private keys, sources, tags, env vars, S3)
            $team->delete();

            // 5. Delete subscription (after team since team.deleting doesn't handle it)
            Subscription::where('team_id', $team->id)->delete();
        }

        // 6. Delete orphaned users (no remaining team memberships)
        foreach ($orphanedUserIds as $userId) {
            $user = User::find($userId);
            if ($user && $user->teams()->count() === 0) {
                $user->delete();
            }
        }

        $this->line('Cleanup complete.');

        return self::SUCCESS;
    }
}
