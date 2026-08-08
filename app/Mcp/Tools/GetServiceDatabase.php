<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\ServiceDatabase;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class GetServiceDatabase extends Tool
{
    protected string $name = 'get_service_database';

    protected string $description = 'Get a service database component by UUID, scoped to the authenticated team.';

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

        $db = ServiceDatabase::ownedByCurrentTeamAPI($teamId)
            ->where('uuid', $uuid)
            ->whereHas('service', fn ($q) => $q->where('uuid', $serviceUuid))
            ->first();

        if (! $db) {
            return $this->mcpError($request, "Service database [{$uuid}] not found.", ['resource_uuid' => $uuid]);
        }

        // Explicit whitelist so new model columns stay private by default (fail-closed).
        $data = $this->scrubSensitive([
            'uuid' => $db->uuid,
            'service_uuid' => $serviceUuid,
            'name' => $db->name,
            'human_name' => $db->human_name,
            'description' => $db->description,
            'status' => $db->status,
            'fqdn' => $db->fqdn,
            'ports' => $db->ports,
            'exposes' => $db->exposes,
            'image' => $db->image,
            'exclude_from_status' => $db->exclude_from_status,
            'public_port' => $db->public_port,
            'is_public' => $db->is_public,
            'is_log_drain_enabled' => $db->is_log_drain_enabled,
            'is_include_timestamps' => $db->is_include_timestamps,
            'is_gzip_enabled' => $db->is_gzip_enabled,
            'is_stripprefix_enabled' => $db->is_stripprefix_enabled,
            'custom_type' => $db->custom_type,
            'last_online_at' => $db->last_online_at,
            'created_at' => $db->created_at,
            'updated_at' => $db->updated_at,
        ]);

        return $this->mcpSuccess($request, $this->respond($data, [
            ['tool' => 'get_logs', 'args' => ['resource' => 'service_database', 'uuid' => $uuid, 'parent_uuid' => $serviceUuid], 'hint' => 'Container logs'],
        ]), ['resource_uuid' => $uuid]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'service_uuid' => $schema->string()->description('Parent service UUID.')->required(),
            'uuid' => $schema->string()->description('Service database UUID.')->required(),
        ];
    }
}
