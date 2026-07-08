<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Service;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('list_service_containers')]
#[Description('List the sub-resources (applications and databases) of a service stack, with their container names and current status. Use get_service_container_logs to tail one of them.')]
class ListServiceContainers extends Tool
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

        $service = Service::whereRelation('environment.project.team', 'id', $teamId)
            ->where('uuid', $uuid)
            ->first();

        if (! $service) {
            return Response::error("Service [{$uuid}] not found.");
        }

        $containers = $service->applications()->get()->map(fn ($application) => [
            'name' => $application->name,
            'container_name' => "{$application->name}-{$service->uuid}",
            'type' => 'application',
            'status' => $application->status,
            'image' => $application->image,
        ])->concat($service->databases()->get()->map(fn ($database) => [
            'name' => $database->name,
            'container_name' => "{$database->name}-{$service->uuid}",
            'type' => 'database',
            'status' => $database->status,
            'image' => $database->image,
        ]))->values();

        $actions = $containers->map(fn ($container) => [
            'tool' => 'get_service_container_logs',
            'args' => ['uuid' => $uuid, 'container' => $container['container_name']],
            'hint' => "Tail logs for {$container['container_name']}",
        ])->all();

        return $this->respond($containers->all(), $actions);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'uuid' => $schema->string()->description('Service UUID.')->required(),
        ];
    }
}
