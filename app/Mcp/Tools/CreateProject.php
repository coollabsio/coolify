<?php

namespace App\Mcp\Tools;

use App\Actions\Project\CreateProject as CreateProjectAction;
use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class CreateProject extends Tool
{
    use BuildsResponse;
    use ResolvesTeam;

    protected string $name = 'create_project';

    protected string $description = 'Create a project in the authenticated team. A default "production" environment is created automatically. Requires write ability.';

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

        $project = CreateProjectAction::run($teamId, $name, $request->get('description'));

        auditLog('mcp.create_project', ['team_id' => $teamId, 'uuid' => $project->uuid]);

        return $this->mcpSuccess($request, $this->respond([
            'ok' => true,
            'uuid' => $project->uuid,
            'next_tools' => [['tool' => 'get_project', 'args' => ['uuid' => $project->uuid]]],
        ]), ['resource_uuid' => $project->uuid]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Project name.')->required(),
            'description' => $schema->string()->description('Optional description.'),
        ];
    }
}
