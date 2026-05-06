<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Project;
use App\Models\Server;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('get_infrastructure_overview')]
#[Description('High-level overview of the authenticated team: Coolify version, all servers, projects with resource counts, and aggregate counts. Start here to understand the setup.')]
class GetInfrastructureOverview extends Tool
{
    use BuildsResponse;
    use ResolvesTeam;

    public function handle(Request $request): Response
    {
        if ($error = $this->ensureAbility($request, 'read')) {
            return $error;
        }

        $teamId = $this->resolveTeamId($request);
        if (is_null($teamId)) {
            return Response::error('Invalid token.');
        }

        $servers = Server::whereTeamId($teamId)
            ->select('id', 'name', 'uuid', 'ip', 'description')
            ->with('settings:id,server_id,is_reachable,is_usable')
            ->get()
            ->map(fn ($s) => [
                'uuid' => $s->uuid,
                'name' => $s->name,
                'ip' => $s->ip,
                'is_reachable' => $s->settings?->is_reachable,
                'is_usable' => $s->settings?->is_usable,
            ])
            ->values()
            ->all();

        $projects = Project::where('team_id', $teamId)->get();

        $appCount = 0;
        $serviceCount = 0;
        $databaseCount = 0;
        $projectSummaries = [];

        foreach ($projects as $project) {
            $apps = $project->applications()->count();
            $services = $project->services()->count();
            $databases = $project->databases()->count();

            $appCount += $apps;
            $serviceCount += $services;
            $databaseCount += $databases;

            $projectSummaries[] = [
                'uuid' => $project->uuid,
                'name' => $project->name,
                'counts' => [
                    'applications' => $apps,
                    'services' => $services,
                    'databases' => $databases,
                ],
            ];
        }

        return $this->respond([
            'coolify_version' => config('constants.coolify.version'),
            'servers' => $servers,
            'projects' => $projectSummaries,
            'counts' => [
                'servers' => count($servers),
                'projects' => count($projectSummaries),
                'applications' => $appCount,
                'services' => $serviceCount,
                'databases' => $databaseCount,
            ],
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
