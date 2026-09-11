<?php

namespace App\Ai\Tools;

use App\Actions\Database\CreateDatabase as CreateDatabaseAction;
use App\Actions\Shared\ResolveResourcePlacement;
use App\Ai\Concerns\AuthorizesToolAction;
use App\Enums\NewDatabaseTypes;
use App\Exceptions\ResourcePlacementException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class CreateDatabase implements Approvable, Tool
{
    use AuthorizesToolAction;
    use InteractsWithApprovals;

    public function description(): string
    {
        return 'Create a standalone database (postgresql|mysql|mariadb|mongodb|redis|keydb|dragonfly|clickhouse) '
            .'in a project/environment on a server in the current team. Requires admin or owner role and human approval. '
            .'Provide type, project_uuid, server_uuid, and environment_name (or environment_uuid).';
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
            'image' => $schema->string()->description('Optional docker image override.'),
            'instant_deploy' => $schema->boolean()->description('Start the database immediately after creation.'),
        ];
    }

    protected function needsApproval(Request $request): Approval|bool
    {
        $this->authorizeToolGate('createAnyResource', 'create_database');

        $args = $request->all();
        $type = (string) ($args['type'] ?? 'database');
        $instant = filter_var($args['instant_deploy'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $where = ($args['project_uuid'] ?? '?').'/'.($args['environment_name'] ?? $args['environment_uuid'] ?? '?');

        return Approval::required("Create {$type} database in {$where} on server ".($args['server_uuid'] ?? '?').'.'
            .($instant ? ' It will deploy immediately.' : ''));
    }

    public function handle(Request $request): string
    {
        $args = $request->validate([
            'type' => 'required|string',
            'project_uuid' => 'required|string',
            'server_uuid' => 'required|string',
            'environment_name' => 'nullable|string',
            'environment_uuid' => 'nullable|string',
            'destination_uuid' => 'nullable|string',
            'name' => 'nullable|string',
            'image' => 'nullable|string',
            'instant_deploy' => 'nullable|boolean',
        ]);

        $this->authorizeToolGate('createAnyResource', 'create_database');

        $type = NewDatabaseTypes::tryFrom($args['type']);
        if (! $type) {
            return "Unknown database type [{$args['type']}].";
        }

        try {
            $placement = ResolveResourcePlacement::run(
                $this->actingTeamId(),
                $args['project_uuid'],
                $args['environment_name'] ?? null,
                $args['environment_uuid'] ?? null,
                $args['server_uuid'],
                $args['destination_uuid'] ?? null,
            );
            $database = CreateDatabaseAction::run(
                $placement,
                $type,
                array_filter(['name' => $args['name'] ?? null]),
                $args['image'] ?? null,
                filter_var($args['instant_deploy'] ?? false, FILTER_VALIDATE_BOOLEAN),
            );
        } catch (ResourcePlacementException $e) {
            return $e->getMessage();
        }

        $this->auditToolCall('create_database', 'success', ['type' => $type->value, 'resource_uuid' => $database->uuid]);

        return "Created {$type->value} database [{$database->uuid}].";
    }
}
