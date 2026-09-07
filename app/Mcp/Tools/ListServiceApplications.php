<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Service;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ListServiceApplications extends Tool
{
    protected string $name = 'list_service_applications';

    protected string $description = 'List application components of a service stack owned by the authenticated team.';

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

        $service = Service::whereRelation('environment.project.team', 'id', $teamId)
            ->where('uuid', $uuid)
            ->first();

        if (! $service) {
            return $this->mcpError($request, "Service [{$uuid}] not found.", ['resource_uuid' => $uuid]);
        }

        $apps = $service->applications()
            ->get()
            ->map(fn ($app) => $this->scrubSensitive([
                'uuid' => $app->uuid,
                'name' => $app->name,
                'human_name' => $app->human_name ?? null,
                'status' => $app->status,
                'fqdn' => $app->fqdn,
                'image' => $app->image ?? null,
            ]))
            ->values()
            ->all();

        return $this->mcpSuccess($request, $this->respond([
            'service_uuid' => $uuid,
            'applications' => $apps,
        ]), ['resource_uuid' => $uuid]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'uuid' => $schema->string()->description('Service UUID.')->required(),
        ];
    }
}
