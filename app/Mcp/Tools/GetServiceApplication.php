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

        // Explicit whitelist so new model columns stay private by default (fail-closed).
        $data = $this->scrubSensitive([
            'uuid' => $app->uuid,
            'service_uuid' => $serviceUuid,
            'name' => $app->name,
            'human_name' => $app->human_name,
            'description' => $app->description,
            'status' => $app->status,
            'fqdn' => $app->fqdn,
            'ports' => $app->ports,
            'exposes' => $app->exposes,
            'image' => $app->image,
            'exclude_from_status' => $app->exclude_from_status,
            'required_fqdn' => $app->required_fqdn,
            'is_log_drain_enabled' => $app->is_log_drain_enabled,
            'is_include_timestamps' => $app->is_include_timestamps,
            'is_gzip_enabled' => $app->is_gzip_enabled,
            'is_stripprefix_enabled' => $app->is_stripprefix_enabled,
            'last_online_at' => $app->last_online_at,
            'created_at' => $app->created_at,
            'updated_at' => $app->updated_at,
        ]);

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
