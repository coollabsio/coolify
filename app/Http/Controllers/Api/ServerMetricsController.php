<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class ServerMetricsController extends Controller
{
    #[OA\Get(
        summary: 'Get server metrics',
        description: 'Get CPU and memory metrics collected by Sentinel for a server owned by the authenticated team.',
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
            new OA\Parameter(
                name: 'minutes',
                in: 'query',
                required: false,
                description: 'Number of minutes of metric history to return. Cannot exceed the server configured Sentinel metrics retention period.',
                schema: new OA\Schema(
                    type: 'integer',
                    default: 10,
                    minimum: 1,
                ),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Server CPU and memory metrics.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'cpu',
                            type: 'array',
                            nullable: true,
                            items: new OA\Items(
                                type: 'array',
                                items: new OA\Items(type: 'number'),
                            ),
                        ),
                        new OA\Property(
                            property: 'memory',
                            type: 'array',
                            nullable: true,
                            items: new OA\Items(
                                type: 'array',
                                items: new OA\Items(type: 'number'),
                            ),
                        ),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ],
    )]
    public function show(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();

        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $validator = customApiValidator($request->query(), [
            'minutes' => 'sometimes|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $minutes = (int) $request->query('minutes', 10);

        $server = Server::whereTeamId($teamId)
            ->whereUuid($request->uuid)
            ->first();

        if (! $server) {
            return response()->json([
                'message' => 'Server not found.',
            ], 404);
        }

        $this->authorize('view', $server);

        $retentionDays = (int) $server->settings->sentinel_metrics_history_days;
        $maxMinutes = $retentionDays * 24 * 60;

        if ($minutes > $maxMinutes) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => [
                    'minutes' => [
                        "The requested metrics history exceeds the configured Sentinel retention period of {$retentionDays} day(s).",
                    ],
                ],
            ], 422);
        }

        return response()->json([
            'cpu' => $server->getCpuMetrics($minutes),
            'memory' => $server->getMemoryMetrics($minutes),
        ]);
    }
}
