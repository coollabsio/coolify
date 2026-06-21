<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Application;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('get_application')]
#[Description('Get full details for a single application by UUID.')]
class GetApplication extends Tool
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

        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $uuid)->first();
        if (! $application) {
            return Response::error("Application [{$uuid}] not found.");
        }

        // Drop relations that the server_status accessor lazy-loads — they
        // pull in sensitive nested data (server.settings.sentinel_token, etc.)
        $application->setRelations([]);
        $application->makeHidden(['destination', 'source', 'additional_servers', 'environment', 'tags', 'environmentVariables']);

        return $this->respond(
            $this->scrubSensitive($application->toArray()),
            $this->actionsForApplication($uuid, $application->status),
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'uuid' => $schema->string()->description('Application UUID.')->required(),
        ];
    }
}
