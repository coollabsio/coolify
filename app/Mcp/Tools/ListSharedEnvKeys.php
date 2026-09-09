<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Environment;
use App\Models\Project;
use App\Models\SharedEnvironmentVariable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ListSharedEnvKeys extends Tool
{
    protected string $name = 'list_shared_env_keys';

    protected string $description = 'List shared environment variable key names (never values) for a project or environment owned by the authenticated team. Scope: project | environment.';

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

        $scope = $request->get('scope');
        $uuid = $request->get('uuid');

        if (! is_string($scope) || ! in_array($scope, ['project', 'environment'], true)) {
            return $this->mcpError($request, 'scope must be project or environment.');
        }
        if (! is_string($uuid) || $uuid === '') {
            return $this->mcpError($request, 'uuid argument is required.');
        }

        if ($scope === 'project') {
            $project = Project::where('team_id', $teamId)->where('uuid', $uuid)->first();
            if (! $project) {
                return $this->mcpError($request, "Project [{$uuid}] not found.", ['resource_uuid' => $uuid]);
            }

            $vars = SharedEnvironmentVariable::query()
                ->where('team_id', $teamId)
                ->where('project_id', $project->id)
                ->where('type', 'project')
                ->orderBy('key')
                ->get();
        } else {
            $environment = Environment::ownedByCurrentTeamAPI($teamId)
                ->with('project:id,uuid,team_id')
                ->where('uuid', $uuid)
                ->first();

            if (! $environment) {
                // Also allow lookup by name under project_uuid if provided.
                $projectUuid = $request->get('project_uuid');
                if (is_string($projectUuid) && $projectUuid !== '') {
                    $project = Project::where('team_id', $teamId)->where('uuid', $projectUuid)->first();
                    if ($project) {
                        $environment = Environment::where('project_id', $project->id)
                            ->with('project:id,uuid,team_id')
                            ->where(function ($q) use ($uuid) {
                                $q->where('uuid', $uuid)->orWhere('name', $uuid);
                            })
                            ->first();
                    }
                }
            }

            if (! $environment || (int) $environment->project?->team_id !== $teamId) {
                return $this->mcpError($request, "Environment [{$uuid}] not found.", ['resource_uuid' => $uuid]);
            }

            $vars = SharedEnvironmentVariable::query()
                ->where('team_id', $teamId)
                ->where('environment_id', $environment->id)
                ->where('type', 'environment')
                ->orderBy('key')
                ->get();

            $uuid = $environment->uuid;
        }

        $keys = $vars->map(fn ($var) => [
            'key' => $var->key,
            'is_literal' => (bool) ($var->is_literal ?? false),
            'is_multiline' => (bool) ($var->is_multiline ?? false),
            'is_shown_once' => (bool) ($var->is_shown_once ?? false),
            'comment' => $var->comment,
            'type' => $var->type,
        ])->values()->all();

        return $this->mcpSuccess($request, $this->respond([
            'scope' => $scope,
            'uuid' => $uuid,
            'keys' => $keys,
        ]), ['resource_uuid' => $uuid]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'scope' => $schema->string()->description('project | environment')->required(),
            'uuid' => $schema->string()->description('Project UUID, or environment UUID (or name with project_uuid).')->required(),
            'project_uuid' => $schema->string()->description('Optional project UUID when resolving environment by name.'),
        ];
    }
}
