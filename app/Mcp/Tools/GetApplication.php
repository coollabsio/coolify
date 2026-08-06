<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Application;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class GetApplication extends Tool
{
    protected string $name = 'get_application';

    protected string $description = 'Get full details for a single application by UUID.';

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

        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $uuid)->first();
        if (! $application) {
            return $this->mcpError($request, "Application [{$uuid}] not found.", ['resource_uuid' => $uuid]);
        }

        // Drop relations that the server_status accessor lazy-loads — they
        // pull in sensitive nested data (server.settings.sentinel_token, etc.)
        $application->setRelations([]);
        $application->makeHidden(['destination', 'source', 'additional_servers', 'environment', 'tags', 'environmentVariables']);

        return $this->mcpSuccess($request, $this->respond(
            $this->scrubSensitive($application->toArray()),
            $this->actionsForApplication($uuid, $application->status),
        ), ['resource_uuid' => $uuid]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'uuid' => $schema->string()->description('Application UUID.')->required(),
        ];
    }
}
