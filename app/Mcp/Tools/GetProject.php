<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class GetProject extends Tool
{
    protected string $name = 'get_project';

    protected string $description = 'Get a project by UUID for the authenticated team, including environments and resource counts.';

    use BuildsResponse;
    use ResolvesTeam;

    public function handle(Request $request): Response
    {
        if ($error = $this->ensureAbility($request, 'read', $this->name)) {
            return $error;
        }

        $teamId = $this->resolveTeamId($request);
        if (is_null($teamId)) {
            return $this->mcpError($request, 'Invalid token.');
        }

        $uuid = $request->get('uuid');
        if (! is_string($uuid) || $uuid === '') {
            return $this->mcpError($request, 'uuid argument is required.');
        }

        $project = Project::where('team_id', $teamId)->where('uuid', $uuid)->first();
        if (! $project) {
            return $this->mcpError($request, "Project [{$uuid}] not found.", ['resource_uuid' => $uuid]);
        }

        $environments = $project->environments()
            ->orderBy('name')
            ->get()
            ->map(fn ($env) => [
                'uuid' => $env->uuid,
                'name' => $env->name,
            ])
            ->values()
            ->all();

        $data = [
            'uuid' => $project->uuid,
            'name' => $project->name,
            'description' => $project->description,
            'environments' => $environments,
            'counts' => [
                'applications' => $project->applications()->count(),
                'services' => $project->services()->count(),
                'databases' => $project->databases()->count(),
            ],
        ];

        return $this->mcpSuccess($request, $this->respond(
            $this->scrubSensitive($data),
            $this->actionsForProject($uuid),
        ), ['resource_uuid' => $uuid]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'uuid' => $schema->string()->description('Project UUID.')->required(),
        ];
    }
}
