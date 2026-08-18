<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesResource;
use App\Mcp\Concerns\ResolvesTeam;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ListStorages extends Tool
{
    protected string $name = 'list_storages';

    protected string $description = 'List persistent and file storage mounts for an application, database, or service owned by the authenticated team. File contents are never returned.';

    use BuildsResponse;
    use ResolvesResource;
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

        $resourceType = $request->get('resource');
        $uuid = $request->get('uuid');

        if (! is_string($resourceType) || ! $this->isValidResourceType($resourceType, $this->primaryResourceTypes)) {
            return $this->mcpError($request, 'resource must be one of: application, database, service.');
        }
        if (! is_string($uuid) || $uuid === '') {
            return $this->mcpError($request, 'uuid argument is required.');
        }

        $resource = $this->resolveTeamResource($teamId, $resourceType, $uuid);
        if (! $resource) {
            return $this->mcpError($request, ucfirst($resourceType)." [{$uuid}] not found.", ['resource_uuid' => $uuid]);
        }

        $persistent = collect();
        $files = collect();

        if (method_exists($resource, 'persistentStorages')) {
            $persistent = $resource->persistentStorages->map(fn ($s) => $this->scrubSensitive([
                'uuid' => $s->uuid,
                'name' => $s->name,
                'mount_path' => $s->mount_path,
                'host_path' => $s->host_path,
                'is_directory' => $s->is_directory ?? null,
            ]));
        }

        if (method_exists($resource, 'fileStorages')) {
            $files = $resource->fileStorages->map(fn ($s) => $this->scrubSensitive([
                'uuid' => $s->uuid,
                'name' => $s->name ?? null,
                'mount_path' => $s->mount_path,
                'is_directory' => $s->is_directory ?? null,
            ]));
        }

        // Services aggregate child storages
        if ($resourceType === 'service') {
            if (method_exists($resource, 'applications')) {
                foreach ($resource->applications as $app) {
                    if (method_exists($app, 'persistentStorages')) {
                        $persistent = $persistent->merge($app->persistentStorages->map(fn ($s) => $this->scrubSensitive([
                            'uuid' => $s->uuid,
                            'name' => $s->name,
                            'mount_path' => $s->mount_path,
                            'host_path' => $s->host_path,
                            'owner' => 'service_application:'.$app->uuid,
                        ])));
                    }
                }
            }
            if (method_exists($resource, 'databases')) {
                foreach ($resource->databases as $db) {
                    if (method_exists($db, 'persistentStorages')) {
                        $persistent = $persistent->merge($db->persistentStorages->map(fn ($s) => $this->scrubSensitive([
                            'uuid' => $s->uuid,
                            'name' => $s->name,
                            'mount_path' => $s->mount_path,
                            'host_path' => $s->host_path,
                            'owner' => 'service_database:'.$db->uuid,
                        ])));
                    }
                }
            }
        }

        return $this->mcpSuccess($request, $this->respond([
            'resource' => $resourceType,
            'uuid' => $uuid,
            'persistent_storages' => $persistent->values()->all(),
            'file_storages' => $files->values()->all(),
        ]), ['resource_uuid' => $uuid]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()->description('application | database | service')->required(),
            'uuid' => $schema->string()->description('Resource UUID.')->required(),
        ];
    }
}
