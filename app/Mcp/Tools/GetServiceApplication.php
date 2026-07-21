<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\ServiceApplication;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class GetServiceApplication extends Tool
{
    protected string $name = 'get_service_application';

    protected string $description = 'Get a service application component by UUID, scoped to the authenticated team.';

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

        $serviceUuid = $request->get('service_uuid');
        $uuid = $request->get('uuid');

        if (! is_string($serviceUuid) || $serviceUuid === '') {
            return $this->mcpError($request, 'service_uuid argument is required.');
        }
        if (! is_string($uuid) || $uuid === '') {
            return $this->mcpError($request, 'uuid argument is required.');
        }

        $app = ServiceApplication::ownedByCurrentTeamAPI($teamId)
            ->where('uuid', $uuid)
            ->whereHas('service', fn ($q) => $q->where('uuid', $serviceUuid))
            ->first();

        if (! $app) {
            return $this->mcpError($request, "Service application [{$uuid}] not found.", ['resource_uuid' => $uuid]);
        }

        $app->setRelations([]);
        $data = $this->scrubSensitive($app->toArray());
        $data['service_uuid'] = $serviceUuid;

        return $this->mcpSuccess($request, $this->respond($data, [
            ['tool' => 'get_logs', 'args' => ['resource' => 'service_application', 'uuid' => $uuid, 'parent_uuid' => $serviceUuid], 'hint' => 'Container logs'],
        ]), ['resource_uuid' => $uuid]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'service_uuid' => $schema->string()->description('Parent service UUID.')->required(),
            'uuid' => $schema->string()->description('Service application UUID.')->required(),
        ];
    }
}
