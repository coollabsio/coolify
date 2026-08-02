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

    protected string $description = 'Fetch recent container logs for an application, database, service, or service child. Requires read:sensitive ability. Output is best-effort redacted (not a guarantee). Multi-container services require resource=service_application|service_database. Requires a running container on a reachable server. On failure returns structured reason + next_tools (DB-only follow-ups). Default 100 lines, max 500.';

    use BuildsResponse;
    use ResolvesResource;
    use ResolvesTeam;

    public function handle(Request $request): Response
    {
        if ($error = $this->ensureAbility($request, 'read:sensitive', $this->name)) {
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

        $failure = $this->preflightFailure($resource, $resourceType, $uuid);
        if ($failure !== null) {
            return $this->mcpSuccess($request, $this->respond($failure), ['resource_uuid' => $uuid, 'outcome' => 'unavailable']);
        }

        try {
            $logs = $this->redactLogText(
                $this->fetchLogs($resource, $resourceType, $lines, $showTimestamps)
            );
        } catch (MultipleContainersException $e) {
            $payload = $this->failurePayload(
                reason: 'multiple_containers',
                message: $e->getMessage(),
                resourceType: $resourceType,
                uuid: $uuid,
                status: $resource->status ?? null,
                server: $this->resolveServerMeta($resource, $resourceType),
            );
            $payload['choices'] = $e->choices;

            return $this->mcpSuccess($request, $this->respond($payload), ['resource_uuid' => $uuid, 'outcome' => 'multiple_containers']);
        } catch (\Throwable $e) {
            return $this->mcpSuccess($request, $this->respond(
                $this->failurePayload(
                    reason: 'log_fetch_failed',
                    message: $e->getMessage(),
                    resourceType: $resourceType,
                    uuid: $uuid,
                    status: $resource->status ?? null,
                    server: $this->resolveServerMeta($resource, $resourceType),
                )
            ), ['resource_uuid' => $uuid, 'outcome' => 'error']);
        }

        return $this->mcpSuccess($request, $this->respond([
            'ok' => true,
            'resource' => $resourceType,
            'uuid' => $uuid,
            'lines' => $lines,
            'logs' => $logs,
            'redacted' => true,
        ]), ['resource_uuid' => $uuid]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function preflightFailure(mixed $resource, string $resourceType, string $uuid): ?array
    {
        $status = $resource->status ?? null;
        $serverMeta = $this->resolveServerMeta($resource, $resourceType);

        // Prefer status-based failure (DB-only) before host reachability so agents
        // can continue with deploy history without needing a live SSH path.
        if (is_string($status) && $status !== '' && ! str_starts_with(strtolower($status), 'running')) {
            return $this->failurePayload(
                reason: 'not_running',
                message: 'Resource is not running; live container logs are unavailable.',
                resourceType: $resourceType,
                uuid: $uuid,
                status: $status,
                server: $serverMeta,
            );
        }

        if ($serverMeta === null) {
            return $this->failurePayload(
                reason: 'no_server',
                message: 'Resource has no server destination; cannot fetch logs.',
                resourceType: $resourceType,
                uuid: $uuid,
                status: $status,
                server: null,
            );
        }

        if ($serverMeta['is_reachable'] === false) {
            return $this->failurePayload(
                reason: 'server_unreachable',
                message: 'Destination server is not reachable from Coolify; cannot fetch live logs.',
                resourceType: $resourceType,
                uuid: $uuid,
                status: $status,
                server: $serverMeta,
            );
        }

        return null;
    }

    /**
     * @param  array{uuid: ?string, name: ?string, is_reachable: ?bool}|null  $server
     * @return array<string, mixed>
     */
    private function failurePayload(
        string $reason,
        string $message,
        string $resourceType,
        string $uuid,
        mixed $status,
        ?array $server,
    ): array {
        $next = [
            ['tool' => 'list_unhealthy_resources', 'args' => new \stdClass, 'hint' => 'See all unhealthy resources'],
            ['tool' => 'list_deployments', 'args' => $resourceType === 'application' ? ['application_uuid' => $uuid] : new \stdClass, 'hint' => 'Check deploy history'],
        ];

        if ($resourceType === 'application') {
            $next[] = ['tool' => 'get_application', 'args' => ['uuid' => $uuid], 'hint' => 'Refresh application status'];
            $next[] = ['tool' => 'get_deployment', 'args' => ['uuid' => '{deployment_uuid}', 'include_log_summary' => true], 'hint' => 'If a failed deploy exists, use its UUID'];
            $next[] = ['tool' => 'control', 'args' => ['resource' => 'application', 'action' => 'start', 'uuid' => $uuid], 'hint' => 'Start/deploy if token has deploy ability'];
        } elseif ($resourceType === 'database') {
            $next[] = ['tool' => 'get_database', 'args' => ['uuid' => $uuid], 'hint' => 'Refresh database status'];
            $next[] = ['tool' => 'control', 'args' => ['resource' => 'database', 'action' => 'start', 'uuid' => $uuid], 'hint' => 'Start if token has deploy ability'];
        } elseif ($resourceType === 'service') {
            $next[] = ['tool' => 'get_service', 'args' => ['uuid' => $uuid], 'hint' => 'Refresh service status'];
            $next[] = ['tool' => 'control', 'args' => ['resource' => 'service', 'action' => 'start', 'uuid' => $uuid], 'hint' => 'Start if token has deploy ability'];
        }

        return [
            'ok' => false,
            'reason' => $reason,
            'message' => $message,
            'resource' => $resourceType,
            'uuid' => $uuid,
            'status' => $status,
            'server' => $server,
            'next_tools' => $next,
        ];
    }

    /**
     * @return array{uuid: ?string, name: ?string, is_reachable: ?bool}|null
     */
    private function resolveServerMeta(mixed $resource, string $resourceType): ?array
    {
        $server = null;
        if ($resource instanceof Application || $resourceType === 'database') {
            $server = $resource->destination?->server;
        } elseif ($resource instanceof Service) {
            $server = $resource->server;
        } elseif ($resource instanceof ServiceApplication || $resource instanceof ServiceDatabase) {
            $server = $resource->service?->server;
        }

        if (! $server) {
            return null;
        }

        return [
            'uuid' => $server->uuid,
            'name' => $server->name,
            'is_reachable' => $server->settings?->is_reachable,
        ];
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
                throw new \RuntimeException('Application has no running containers.');
            }
            $container = $containers->first();
            $status = getContainerStatus($server, $container['Names']);
            if ($status !== 'running') {
                throw new \RuntimeException('Application container is not running.');
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

            $apps = $resource->applications()->get(['id', 'uuid', 'name', 'service_id', 'status']);
            $dbs = $resource->databases()->get(['id', 'uuid', 'name', 'service_id', 'status']);
            $total = $apps->count() + $dbs->count();

            if ($total === 0) {
                throw new \RuntimeException('Service has no containers.');
            }

            // Multi-container services need an explicit child resource type.
            if ($total > 1) {
                $choices = $apps->map(fn ($app) => [
                    'resource' => 'service_application',
                    'uuid' => $app->uuid,
                    'name' => $app->name,
                ])->concat($dbs->map(fn ($db) => [
                    'resource' => 'service_database',
                    'uuid' => $db->uuid,
                    'name' => $db->name,
                ]))->values()->all();

                throw new MultipleContainersException(
                    'Service has multiple containers. Call get_logs with resource=service_application or service_database and the child uuid.',
                    $choices,
                );
            }

            $child = $apps->first() ?? $dbs->first();
            $containerName = $child->name.'-'.$resource->uuid;
            $status = getContainerStatus($server, $containerName);
            if ($status !== 'running') {
                throw new \RuntimeException('Service container is not running.');
            }

            return (string) getContainerLogs($server, $containerName, $lines, $showTimestamps);
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

/**
 * Service has more than one child container; caller must pick service_application or service_database.
 */
class MultipleContainersException extends \RuntimeException
{
    /**
     * @param  list<array{resource: string, uuid: string, name: mixed}>  $choices
     */
    public function __construct(
        string $message,
        public readonly array $choices,
    ) {
        parent::__construct($message);
    }
}
