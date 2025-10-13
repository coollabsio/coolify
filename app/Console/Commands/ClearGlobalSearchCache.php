<?php

namespace App\Console\Commands;

use App\Livewire\GlobalSearch;
use App\Models\Team;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ClearGlobalSearchCache extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'search:clear {--team= : Clear cache for specific team ID} {--all : Clear cache for all teams}';

    /**
     * The console command description.
     */
    protected $description = 'Clear the global search cache for testing or manual refresh';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->option('all')) {
            return $this->clearAllTeamsCache();
        }

        if ($teamId = $this->option('team')) {
            return $this->clearTeamCache($teamId);
        }

        // If no options provided, clear cache for current user's team
        if (! auth()->check()) {
            $this->error('No authenticated user found. Use --team=ID or --all option.');

            return Command::FAILURE;
        }

        $teamId = auth()->user()->currentTeam()->id;

        return $this->clearTeamCache($teamId);
    }

    private function clearTeamCache(int $teamId): int
    {
        $team = Team::find($teamId);

        if (! $team) {
            $this->error("Team with ID {$teamId} not found.");

            return Command::FAILURE;
        }

        GlobalSearch::clearTeamCache($teamId);
        $this->info("✓ Cleared global search cache for team: {$team->name} (ID: {$teamId})");

        return Command::SUCCESS;
    }

    private function clearAllTeamsCache(): int
    {
        $teams = Team::all();

        if ($teams->isEmpty()) {
            $this->warn('No teams found.');

            return Command::SUCCESS;
        }

        $count = 0;
        foreach ($teams as $team) {
            GlobalSearch::clearTeamCache($team->id);
            $count++;
        }

        $this->info("✓ Cleared global search cache for {$count} team(s)");

        return Command::SUCCESS;
    }
}
