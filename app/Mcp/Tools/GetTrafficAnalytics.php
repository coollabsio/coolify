<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesResource;
use App\Mcp\Concerns\ResolvesTeam;
use App\Services\ResourceTrafficReport;
use App\Services\TrafficResource;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetTrafficAnalytics extends Tool
{
    use BuildsResponse;
    use ResolvesResource;
    use ResolvesTeam;

    private const RANGES = ['24h', '7d', '30d'];

    protected string $name = 'get_traffic_analytics';

    protected string $description = 'Get the traffic analytics that Sentinel records from the proxy access log for one application or service of the authenticated team: requests, unique visitors, bytes, error rate, latency percentiles, a time series, top paths, and top values per dimension (country, city, referer, browser, os, device, protocol, cache, status, agent). Traffic analytics must be enabled on the server. Requires read ability. The ip and useragent breakdowns need read:sensitive and a team admin or owner.';

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
        if (! is_string($resourceType) || ! in_array($resourceType, ['application', 'service'], true)) {
            return $this->mcpError($request, 'resource must be one of: application, service.');
        }

        $uuid = $request->get('uuid');
        if (! is_string($uuid) || $uuid === '') {
            return $this->mcpError($request, 'uuid argument is required.');
        }

        $range = $request->get('range', '24h');
        if (! in_array($range, self::RANGES, true)) {
            return $this->mcpError($request, 'range must be one of: '.implode(', ', self::RANGES).'.');
        }

        $model = $this->resolveTeamResource($teamId, $resourceType, $uuid);
        if (! $model) {
            return $this->mcpError($request, ucfirst($resourceType)." [{$uuid}] not found.", ['resource_uuid' => $uuid]);
        }

        $resource = TrafficResource::for($model);
        $server = $resource->server();
        if (! $server?->isTrafficAnalyticsEnabled()) {
            return $this->mcpError($request, 'Traffic analytics is not enabled for the server of this resource. Enable traffic analytics in the server settings first.', ['resource_uuid' => $uuid]);
        }

        try {
            $report = ResourceTrafficReport::build($resource, $server, $range, $this->canReadSensitiveData($request));
        } catch (\Throwable $e) {
            report($e);

            return $this->mcpError($request, 'Failed to fetch traffic analytics from Sentinel.', ['resource_uuid' => $uuid]);
        }

        return $this->mcpSuccess($request, $this->respond([
            'resource' => $resourceType,
            'uuid' => $resource->uuid(),
            'name' => $resource->name(),
            ...$report,
        ]), ['resource_uuid' => $uuid, 'range' => $range]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()->enum(['application', 'service'])->description('Resource type.')->required(),
            'uuid' => $schema->string()->description('Application or service UUID.')->required(),
            'range' => $schema->string()->enum(self::RANGES)->description('Time range (default 24h).'),
        ];
    }
}
