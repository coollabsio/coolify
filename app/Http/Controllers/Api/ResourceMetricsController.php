<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ValidatesMetricsWindow;
use App\Http\Controllers\Controller;
use App\Models\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Throwable;

#[OA\Schema(schema: 'MetricSeries', type: 'array', nullable: true, description: 'Data points as [unix_timestamp_ms, value].', items: new OA\Items(type: 'array', items: new OA\Items(type: 'number')), example: [[1787259000000, 12.5], [1787259060000, 17.25]])]
#[OA\Schema(
    schema: 'ContainerMetrics',
    type: 'object',
    properties: [
        new OA\Property(property: 'cpu', ref: '#/components/schemas/MetricSeries', description: 'CPU usage of the container in percent. Null when metrics are disabled for the server.'),
        new OA\Property(property: 'memory', ref: '#/components/schemas/MetricSeries', description: 'Memory usage of the container in MB. Null when metrics are disabled for the server.'),
    ],
)]
class ResourceMetricsController extends Controller
{
    use ValidatesMetricsWindow;

    #[OA\Get(
        summary: 'Get application metrics',
        description: 'Get the CPU and memory history that Sentinel collects for the container of an application. Send minutes, or from with an optional to. The retention period is the one of the server of the application.',
        path: '/applications/{uuid}/metrics',
        operationId: 'get-application-metrics-by-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Applications'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the application.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(ref: '#/components/parameters/MetricsMinutes'),
            new OA\Parameter(ref: '#/components/parameters/MetricsFrom'),
            new OA\Parameter(ref: '#/components/parameters/MetricsTo'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Container CPU and memory metrics.', content: new OA\JsonContent(ref: '#/components/schemas/ContainerMetrics')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
            new OA\Response(
                response: 502,
                description: 'Sentinel did not return metrics.',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string', example: 'Failed to fetch metrics from Sentinel.')], type: 'object'),
            ),
        ],
    )]
    public function application(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $request->route('uuid'))->first();
        if (! $application) {
            return response()->json(['message' => 'Application not found.'], 404);
        }

        return $this->containerMetrics($request, $application);
    }

    #[OA\Get(
        summary: 'Get database metrics',
        description: 'Get the CPU and memory history that Sentinel collects for the container of a database. Send minutes, or from with an optional to. The retention period is the one of the server of the database.',
        path: '/databases/{uuid}/metrics',
        operationId: 'get-database-metrics-by-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Databases'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the database.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(ref: '#/components/parameters/MetricsMinutes'),
            new OA\Parameter(ref: '#/components/parameters/MetricsFrom'),
            new OA\Parameter(ref: '#/components/parameters/MetricsTo'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Container CPU and memory metrics.', content: new OA\JsonContent(ref: '#/components/schemas/ContainerMetrics')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
            new OA\Response(
                response: 502,
                description: 'Sentinel did not return metrics.',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string', example: 'Failed to fetch metrics from Sentinel.')], type: 'object'),
            ),
        ],
    )]
    public function database(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $database = queryDatabaseByUuidWithinTeam((string) $request->route('uuid'), $teamId);
        if (! $database) {
            return response()->json(['message' => 'Database not found.'], 404);
        }

        return $this->containerMetrics($request, $database);
    }

    /**
     * @param  Model  $resource  an Application or a standalone database (models with HasMetrics)
     */
    private function containerMetrics(Request $request, Model $resource): JsonResponse
    {
        $this->authorize('view', $resource);

        [$minutes, $to] = $this->metricsWindow($request, $resource->destination->server);

        try {
            return response()->json([
                'cpu' => $resource->getCpuMetrics($minutes, $to),
                'memory' => $resource->getMemoryMetrics($minutes, $to),
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Failed to fetch metrics from Sentinel.'], 502);
        }
    }
}
