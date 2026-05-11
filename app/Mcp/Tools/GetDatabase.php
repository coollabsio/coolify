<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('get_database')]
#[Description('Get full details for a standalone database by UUID. Detects type across postgresql, mysql, mariadb, mongodb, redis, keydb, dragonfly, clickhouse.')]
class GetDatabase extends Tool
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

        $uuid = $request->get('uuid');
        if (! is_string($uuid) || $uuid === '') {
            return Response::error('uuid argument is required.');
        }

        $database = queryDatabaseByUuidWithinTeam($uuid, (string) $teamId);
        if (! $database) {
            return Response::error("Database [{$uuid}] not found.");
        }

        // Drop relations so deep server/destination data doesn't leak.
        $database->setRelations([]);
        $database->makeHidden(['destination', 'source', 'environment', 'environment_variables', 'environment_variables_preview']);

        return $this->respond(
            $this->scrubSensitive($database->toArray()),
            $this->actionsForDatabase($uuid, $database->status ?? null),
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'uuid' => $schema->string()->description('Database UUID.')->required(),
        ];
    }
}
