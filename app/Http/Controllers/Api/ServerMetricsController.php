<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ValidatesMetricsWindow;
use App\Http\Controllers\Controller;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Throwable;

#[OA\Parameter(parameter: 'MetricsMinutes', name: 'minutes', in: 'query', required: false, description: 'Number of minutes of metric history before now. Cannot be sent with from or to. Cannot exceed the Sentinel metrics retention period of the server.', schema: new OA\Schema(type: 'integer', default: 10, minimum: 1))]
#[OA\Parameter(parameter: 'MetricsFrom', name: 'from', in: 'query', required: false, description: 'Start of the metric history, as an ISO 8601 date. Cannot be older than the Sentinel metrics retention period of the server. The window length is rounded up to whole minutes, so the history can start up to a minute before from.', schema: new OA\Schema(type: 'string', format: 'date-time', example: '2026-11-20T08:00:00Z'))]
#[OA\Parameter(parameter: 'MetricsTo', name: 'to', in: 'query', required: false, description: 'End of the metric history, as an ISO 8601 date after from. Needs from. Defaults to now.', schema: new OA\Schema(type: 'string', format: 'date-time', example: '2026-11-20T09:00:00Z'))]

class ServerMetricsController extends Controller
{
    use ValidatesMetricsWindow;

    #[OA\Get(
        summary: 'Get server metrics',
        description: 'Get the metric history that Sentinel collects for a server owned by the authenticated team. Send minutes, or from with an optional to. The response has only the requested types. Each type is null when metrics are disabled for the server.',
        path: '/servers/{uuid}/metrics',
        operationId: 'get-server-metrics',
        security: [['bearerAuth' => []]],
        tags: ['Servers'],
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                in: 'path',
                required: true,
                description: 'Server UUID',
                schema: new OA\Schema(type: 'string'),
            ),
            new OA\Parameter(ref: '#/components/parameters/MetricsMinutes'),
            new OA\Parameter(ref: '#/components/parameters/MetricsFrom'),
            new OA\Parameter(ref: '#/components/parameters/MetricsTo'),
            new OA\Parameter(
                name: 'types',
                in: 'query',
                required: false,
                description: 'Comma-separated metric types: cpu, memory, disk, network, load.',
                schema: new OA\Schema(type: 'string', default: 'cpu,memory', example: 'cpu,memory,disk'),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Server metrics of the requested types.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'cpu', ref: '#/components/schemas/MetricSeries', description: 'CPU usage in percent.'),
                        new OA\Property(property: 'memory', ref: '#/components/schemas/MetricSeries', description: 'Memory usage in percent.'),
                        new OA\Property(
                            property: 'disk',
                            type: 'object',
                            nullable: true,
                            description: 'Disk usage in percent, keyed by mount path.',
                            additionalProperties: new OA\AdditionalProperties(ref: '#/components/schemas/MetricSeries'),
                            example: ['/' => [[1787259000000, 41.2]]],
                        ),
                        new OA\Property(
                            property: 'network',
                            type: 'object',
                            nullable: true,
                            description: 'Received and sent bytes per second.',
                            properties: [
                                new OA\Property(property: 'rx', ref: '#/components/schemas/MetricSeries'),
                                new OA\Property(property: 'tx', ref: '#/components/schemas/MetricSeries'),
                            ],
                        ),
                        new OA\Property(
                            property: 'load',
                            type: 'object',
                            nullable: true,
                            description: 'Load averages over 1, 5, and 15 minutes.',
                            properties: [
                                new OA\Property(property: 'load1', ref: '#/components/schemas/MetricSeries'),
                                new OA\Property(property: 'load5', ref: '#/components/schemas/MetricSeries'),
                                new OA\Property(property: 'load15', ref: '#/components/schemas/MetricSeries'),
                            ],
                        ),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
            new OA\Response(
                response: 502,
                description: 'Sentinel did not return metrics.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Failed to fetch metrics from Sentinel.'),
                    ],
                    type: 'object',
                ),
            ),
        ],
    )]
    public function show(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();

        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $server = Server::whereTeamId($teamId)
            ->whereUuid($request->uuid)
            ->first();

        if (! $server) {
            return response()->json([
                'message' => 'Server not found.',
            ], 404);
        }

        $this->authorize('view', $server);

        [$minutes, $to] = $this->metricsWindow($request, $server, [
            'types' => ['bail', 'sometimes', 'string', function (string $attribute, mixed $value, \Closure $fail) {
                $unknown = array_diff(explode(',', (string) $value), array_keys(Server::SERVER_METRIC_TYPES));
                if ($unknown !== []) {
                    $fail('Unknown metric types: '.implode(', ', $unknown).'. Use cpu, memory, disk, network, or load.');
                }
            }],
        ]);

        $types = array_unique(explode(',', (string) $request->query('types', 'cpu,memory')));

        try {
            $metrics = [];
            foreach ($types as $type) {
                $metrics[$type] = $server->getServerMetrics($type, $minutes, $to);
            }

            return response()->json($metrics);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Failed to fetch metrics from Sentinel.',
            ], 502);
        }
    }
}
