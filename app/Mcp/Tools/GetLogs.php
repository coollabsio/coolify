<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesResource;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Application;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class GetLogs extends Tool
{
    protected string $name = 'get_logs';

    protected string $description = 'Fetch recent container logs for an application, database, service, or service child resource owned by the authenticated team. Default 100 lines, max 500.';

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
        $parentUuid = $request->get('parent_uuid');

        if (! is_string($resourceType) || ! $this->isValidResourceType($resourceType, $this->logResourceTypes)) {
            return $this->mcpError($request, 'resource must be one of: application, database, service, service_application, service_database.');
        }
        if (! is_string($uuid) || $uuid === '') {
            return $this->mcpError($request, 'uuid argument is required.');
        }

        $resource = $this->resolveTeamLogResource(
            $teamId,
            $resourceType,
            $uuid,
            is_string($parentUuid) ? $parentUuid : null,
        );

        if (! $resource) {
            return $this->mcpError($request, ucfirst(str_replace('_', ' ', $resourceType))." [{$uuid}] not found.", ['resource_uuid' => $uuid]);
        }

        $lines = $this->normalizeMcpLogLines($request->get('lines'));
        $showTimestamps = parseLogTimestampFlag($request->get('show_timestamps'));

        try {
            $logs = $this->fetchLogs($resource, $resourceType, $lines, $showTimestamps);
        } catch (\Throwable $e) {
            return $this->mcpError($request, $e->getMessage(), ['resource_uuid' => $uuid]);
        }

        return $this->mcpSuccess($request, $this->respond([
            'resource' => $resourceType,
            'uuid' => $uuid,
            'lines' => $lines,
            'logs' => $logs,
        ]), ['resource_uuid' => $uuid]);
    }

    private function fetchLogs(mixed $resource, string $resourceType, int $lines, bool $showTimestamps): string
    {
        if ($resource instanceof Application) {
            $server = $resource->destination?->server;
            if (! $server) {
                throw new \RuntimeException('Application has no server destination.');
            }
            $containers = getCurrentApplicationContainerStatus($server, $resource->id);
            if ($containers->count() === 0) {
                throw new \RuntimeException('Application is not running.');
            }
            $container = $containers->first();
            $status = getContainerStatus($server, $container['Names']);
            if ($status !== 'running') {
                throw new \RuntimeException('Application is not running.');
            }

            return (string) getContainerLogs($server, $container['ID'], $lines, $showTimestamps);
        }

        if ($resourceType === 'database') {
            $server = $resource->destination?->server;
            if (! $server) {
                throw new \RuntimeException('Database has no server destination.');
            }
            $status = getContainerStatus($server, $resource->uuid);
            if ($status !== 'running') {
                throw new \RuntimeException('Database is not running.');
            }

            return (string) getContainerLogs($server, $resource->uuid, $lines, $showTimestamps);
        }

        if ($resource instanceof Service) {
            $server = $resource->server;
            if (! $server) {
                throw new \RuntimeException('Service has no server.');
            }
            // Aggregate first application container if present; otherwise first database container.
            $app = $resource->applications()->first();
            if ($app) {
                $containerName = $app->name.'-'.$resource->uuid;
                $status = getContainerStatus($server, $containerName);
                if ($status !== 'running') {
                    throw new \RuntimeException('Service application container is not running.');
                }

                return (string) getContainerLogs($server, $containerName, $lines, $showTimestamps);
            }
            $db = $resource->databases()->first();
            if ($db) {
                $containerName = $db->name.'-'.$resource->uuid;
                $status = getContainerStatus($server, $containerName);
                if ($status !== 'running') {
                    throw new \RuntimeException('Service database container is not running.');
                }

                return (string) getContainerLogs($server, $containerName, $lines, $showTimestamps);
            }

            throw new \RuntimeException('Service has no containers.');
        }

        if ($resource instanceof ServiceApplication || $resource instanceof ServiceDatabase) {
            $service = $resource->service;
            $server = $service?->server;
            if (! $server) {
                throw new \RuntimeException('Service child has no server.');
            }
            $containerName = $resource->name.'-'.$service->uuid;
            $status = getContainerStatus($server, $containerName);
            if ($status !== 'running') {
                throw new \RuntimeException('Container is not running.');
            }

            return (string) getContainerLogs($server, $containerName, $lines, $showTimestamps);
        }

        throw new \RuntimeException('Unsupported resource type for logs.');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()->description('application | database | service | service_application | service_database')->required(),
            'uuid' => $schema->string()->description('Resource UUID.')->required(),
            'parent_uuid' => $schema->string()->description('Optional parent service UUID for service_application / service_database.'),
            'lines' => $schema->integer()->description('Number of log lines (default 100, max 500).'),
            'show_timestamps' => $schema->boolean()->description('Include timestamps in log output.'),
        ];
    }
}
