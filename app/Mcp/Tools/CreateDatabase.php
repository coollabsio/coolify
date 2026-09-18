<?php

namespace App\Mcp\Tools;

use App\Actions\Database\CreateDatabase as CreateDatabaseAction;
use App\Actions\Shared\ResolveResourcePlacement;
use App\Enums\NewDatabaseTypes;
use App\Exceptions\ResourcePlacementException;
use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class CreateDatabase extends Tool
{
    use BuildsResponse;
    use ResolvesTeam;

    protected string $name = 'create_database';

    protected string $description = 'Create a standalone database (postgresql|mysql|mariadb|mongodb|redis|keydb|dragonfly|clickhouse) in a project/environment on a server owned by the authenticated team. Requires write ability (deploy ability when instant_deploy=true).';

    public function handle(Request $request): Response
    {
        $instantDeploy = filter_var($request->get('instant_deploy'), FILTER_VALIDATE_BOOLEAN);
        if ($error = $this->ensureAbility($request, $instantDeploy ? 'deploy' : 'write', $this->name)) {
            return $error;
        }

        $teamId = $this->resolveTeamId($request);
        if (is_null($teamId)) {
            return $this->mcpError($request, 'Invalid token.');
        }

        $type = NewDatabaseTypes::tryFrom((string) $request->get('type'));
        if (! $type) {
            return $this->mcpError($request, 'type must be one of: '.collect(NewDatabaseTypes::cases())->pluck('value')->implode(', '));
        }

        try {
            $placement = ResolveResourcePlacement::run(
                $teamId,
                (string) $request->get('project_uuid'),
                $request->get('environment_name'),
                $request->get('environment_uuid'),
                (string) $request->get('server_uuid'),
                $request->get('destination_uuid'),
            );
            $database = CreateDatabaseAction::run($placement, $type, array_filter([
                'name' => $request->get('name'),
                'description' => $request->get('description'),
            ]), $request->get('image'), $instantDeploy);
        } catch (ResourcePlacementException $e) {
            return $this->mcpError($request, $e->getMessage());
        }

        auditLog('mcp.create_database', ['team_id' => $teamId, 'uuid' => $database->uuid, 'type' => $type->value]);

        return $this->mcpSuccess($request, $this->respond([
            'ok' => true,
            'uuid' => $database->uuid,
            'type' => $type->value,
            'next_tools' => [['tool' => 'get_database', 'args' => ['uuid' => $database->uuid]]],
        ]), ['resource_uuid' => $database->uuid]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->description('postgresql|mysql|mariadb|mongodb|redis|keydb|dragonfly|clickhouse')->required(),
            'project_uuid' => $schema->string()->description('Project UUID.')->required(),
            'server_uuid' => $schema->string()->description('Server UUID.')->required(),
            'environment_name' => $schema->string()->description('Provide this or environment_uuid.'),
            'environment_uuid' => $schema->string()->description('Provide this or environment_name.'),
            'destination_uuid' => $schema->string()->description('Required only when the server has multiple destinations.'),
            'name' => $schema->string()->description('Optional name.'),
            'description' => $schema->string()->description('Optional description.'),
            'image' => $schema->string()->description('Optional docker image override.'),
            'instant_deploy' => $schema->boolean()->description('Start the database immediately after creation.'),
        ];
    }
}
