<?php

namespace App\Mcp\Tools;

use App\Actions\Project\CreateEnvironment as CreateEnvironmentAction;
use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class CreateEnvironment extends Tool
{
    use BuildsResponse;
    use ResolvesTeam;

    protected string $name = 'create_environment';

    protected string $description = 'Create an environment inside a project owned by the authenticated team. Requires write ability.';

    public function handle(Request $request): Response
    {
        if ($error = $this->ensureAbility($request, 'write', $this->name)) {
            return $error;
        }

        $teamId = $this->resolveTeamId($request);
        if (is_null($teamId)) {
            return $this->mcpError($request, 'Invalid token.');
        }

        $name = $request->get('name');
        if (! is_string($name) || trim($name) === '') {
            return $this->mcpError($request, 'name is required.');
        }

        $project = Project::whereTeamId($teamId)->whereUuid((string) $request->get('project_uuid'))->first();
        if (! $project) {
            return $this->mcpError($request, 'Project not found.');
        }

        try {
            $environment = CreateEnvironmentAction::run($project, $name);
        } catch (\Throwable $e) {
            return $this->mcpError($request, $e->getMessage());
        }

        auditLog('mcp.create_environment', ['team_id' => $teamId, 'uuid' => $environment->uuid, 'project_uuid' => $project->uuid]);

        return $this->mcpSuccess($request, $this->respond([
            'ok' => true,
            'uuid' => $environment->uuid,
            'name' => $environment->name,
            'next_tools' => [['tool' => 'get_environment', 'args' => ['uuid' => $environment->uuid]]],
        ]), ['resource_uuid' => $environment->uuid]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_uuid' => $schema->string()->description('Project UUID.')->required(),
            'name' => $schema->string()->description('Environment name.')->required(),
        ];
    }
}
