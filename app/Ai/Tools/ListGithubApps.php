<?php

namespace App\Ai\Tools;

use App\Ai\Concerns\AuthorizesToolAction;
use App\Models\GithubApp;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class ListGithubApps implements Tool
{
    use AuthorizesToolAction;

    public function description(): string
    {
        return 'List GitHub apps available to the current team (team-owned or system-wide) as "uuid — name [org]" lines. '
            .'Use the uuid as github_app_uuid when creating a private-gh-app application.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): string
    {
        $teamId = $this->actingTeamId();
        $apps = GithubApp::query()
            ->where(fn ($q) => $q->where('team_id', $teamId)->orWhere('is_system_wide', true))
            ->orderBy('name')->get();

        if ($apps->isEmpty()) {
            return 'No GitHub apps are available to this team.';
        }

        return $apps->map(fn (GithubApp $a) => "{$a->uuid} — {$a->name}".(filled($a->organization) ? " [{$a->organization}]" : '').($a->is_system_wide ? ' (system-wide)' : ''))
            ->implode("\n");
    }
}
