<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetDatabase extends Tool
{
    protected string $name = 'get_database';

    protected string $description = 'Get full details for a standalone database by UUID. Detects type across postgresql, mysql, mariadb, mongodb, redis, keydb, dragonfly, clickhouse, sqlite. Custom configuration (database config file, docker run options) needs read:sensitive.';

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

        $database = queryDatabaseByUuidWithinTeam($uuid, (string) $teamId);
        if (! $database) {
            return $this->mcpError($request, "Database [{$uuid}] not found.", ['resource_uuid' => $uuid]);
        }

        // Drop relations so deep server/destination data doesn't leak.
        $database->setRelations([]);
        $database->makeHidden(['destination', 'source', 'environment', 'environment_variables', 'environment_variables_preview']);

        $visibleSensitiveKeys = $this->canReadSensitiveData($request) ? $database::CONFIGURATION_ATTRIBUTES : [];
        $database->makeVisible($visibleSensitiveKeys);

        return $this->mcpSuccess($request, $this->respond(
            $this->scrubSensitive($database->toArray(), $visibleSensitiveKeys),
            $this->actionsForDatabase($uuid, $database->status ?? null),
        ), ['resource_uuid' => $uuid]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'uuid' => $schema->string()->description('Database UUID.')->required(),
        ];
    }
}
