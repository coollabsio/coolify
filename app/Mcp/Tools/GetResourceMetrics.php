<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesResource;
use App\Mcp\Concerns\ResolvesTeam;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetResourceMetrics extends Tool
{
    use BuildsResponse;
    use ResolvesResource;
    use ResolvesTeam;

    private const DEFAULT_MINUTES = 60;

    protected string $name = 'get_resource_metrics';

    protected string $description = 'Get the container CPU % and memory MB history that Sentinel collects for one application or standalone database of the authenticated team. Metrics must be enabled on the server of the resource. Requires read ability.';

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
        if (! is_string($resourceType) || ! in_array($resourceType, ['application', 'database'], true)) {
            return $this->mcpError($request, 'resource must be one of: application, database.');
        }

        $uuid = $request->get('uuid');
        if (! is_string($uuid) || $uuid === '') {
            return $this->mcpError($request, 'uuid argument is required.');
        }

        $minutes = filter_var($request->get('minutes', self::DEFAULT_MINUTES), FILTER_VALIDATE_INT);
        if ($minutes === false || $minutes < 1) {
            return $this->mcpError($request, 'minutes must be a positive integer.');
        }

        $resource = $this->resolveTeamResource($teamId, $resourceType, $uuid);
        if (! $resource) {
            return $this->mcpError($request, ucfirst($resourceType)." [{$uuid}] not found.", ['resource_uuid' => $uuid]);
        }

        $server = $resource->destination->server;
        if (! $server->isMetricsEnabled()) {
            return $this->mcpError($request, 'Metrics are not enabled for the server of this resource. Enable Sentinel metrics in the server settings first.', ['resource_uuid' => $uuid]);
        }

        $retentionDays = (int) $server->settings->sentinel_metrics_history_days;
        $minutes = min($minutes, $retentionDays * 24 * 60);

        try {
            $cpu = $resource->getCpuMetrics($minutes);
            $memory = $resource->getMemoryMetrics($minutes);
        } catch (\Throwable $e) {
            report($e);

            return $this->mcpError($request, 'Failed to fetch metrics from Sentinel.', ['resource_uuid' => $uuid]);
        }

        return $this->mcpSuccess($request, $this->respond([
            'resource' => $resourceType,
            'uuid' => $resource->uuid,
            'name' => $resource->name,
            'minutes' => $minutes,
            'retention_days' => $retentionDays,
            'series' => [
                'cpu_percent' => $cpu ?? [],
                'memory_mb' => $memory ?? [],
            ],
            'series_format' => '[timestamp_ms, value] pairs',
        ]), ['resource_uuid' => $uuid, 'minutes' => $minutes]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()->enum(['application', 'database'])->description('Resource type.')->required(),
            'uuid' => $schema->string()->description('Application or database UUID.')->required(),
            'minutes' => $schema->integer()->description('How many minutes of history to return (default 60). Capped at the Sentinel metrics retention of the server.'),
        ];
    }
}
