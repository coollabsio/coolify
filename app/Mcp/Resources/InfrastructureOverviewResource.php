<?php

namespace App\Mcp\Resources;

use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Project;
use App\Models\Server;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;

class InfrastructureOverviewResource extends Resource
{
    use ResolvesTeam;

    protected string $name = 'coolify-overview';

    protected string $description = 'Team infrastructure overview JSON for the authenticated Coolify token.';

    protected string $uri = 'coolify://overview';

    protected string $mimeType = 'application/json';

    public function handle(Request $request): Response
    {
        if ($error = $this->ensureAbility($request, 'read', $this->name)) {
            return $error;
        }

        $teamId = $this->resolveTeamId($request);
        if (is_null($teamId)) {
            return Response::error('Invalid token.');
        }

        $servers = Server::whereTeamId($teamId)
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

        $projects = Project::where('team_id', $teamId)->get()->map(fn ($p) => [
            'uuid' => $p->uuid,
            'name' => $p->name,
            'counts' => [
                'applications' => $p->applications()->count(),
                'services' => $p->services()->count(),
                'databases' => $p->databases()->count(),
            ],
        ])->values()->all();

        return Response::text(json_encode([
            'coolify_version' => config('constants.coolify.version'),
            'servers' => $servers,
            'projects' => $projects,
            'counts' => [
                'servers' => count($servers),
                'projects' => count($projects),
            ],
        ], JSON_PRETTY_PRINT));
    }
}
