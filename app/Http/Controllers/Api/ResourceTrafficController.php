<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Service;
use App\Services\ResourceTrafficReport;
use App\Services\TrafficResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Throwable;

#[OA\Schema(
    schema: 'ResourceTraffic',
    type: 'object',
    description: 'Traffic analytics that Sentinel records from the proxy access log. When traffic analytics is disabled on the server, enabled is false and every field except range is null.',
    properties: [
        new OA\Property(property: 'enabled', type: 'boolean'),
        new OA\Property(property: 'range', type: 'string', enum: ['24h', '7d', '30d']),
        new OA\Property(property: 'overview', type: 'object', nullable: true, properties: [
            new OA\Property(property: 'requests', type: 'integer'),
            new OA\Property(property: 'unique_visitors', type: 'integer'),
            new OA\Property(property: 'bytes_in', type: 'integer'),
            new OA\Property(property: 'bytes_out', type: 'integer'),
            new OA\Property(property: 'error_rate', type: 'number', description: 'Share of 4xx and 5xx responses in percent.'),
            new OA\Property(property: 'latency', type: 'object', description: 'Response time percentiles in milliseconds.', properties: [
                new OA\Property(property: 'p50', type: 'number'),
                new OA\Property(property: 'p95', type: 'number'),
                new OA\Property(property: 'p99', type: 'number'),
            ]),
            new OA\Property(property: 'status', type: 'object', properties: [
                new OA\Property(property: 's2xx', type: 'integer'),
                new OA\Property(property: 's3xx', type: 'integer'),
                new OA\Property(property: 's4xx', type: 'integer'),
                new OA\Property(property: 's5xx', type: 'integer'),
            ]),
        ]),
        new OA\Property(property: 'latency_approximate', type: 'boolean', nullable: true, description: 'True when the latency merges several sources and takes the worst value.'),
        new OA\Property(property: 'uniques_approximate', type: 'boolean', nullable: true, description: 'True when unique visitors are summed over several sources.'),
        new OA\Property(property: 'series', type: 'array', nullable: true, items: new OA\Items(type: 'object', properties: [
            new OA\Property(property: 'bucket', type: 'integer', description: 'Bucket start as a unix timestamp in milliseconds.'),
            new OA\Property(property: 's2xx', type: 'integer'),
            new OA\Property(property: 's3xx', type: 'integer'),
            new OA\Property(property: 's4xx', type: 'integer'),
            new OA\Property(property: 's5xx', type: 'integer'),
            new OA\Property(property: 'requests', type: 'integer'),
            new OA\Property(property: 'bytes_in', type: 'integer'),
            new OA\Property(property: 'bytes_out', type: 'integer'),
            new OA\Property(property: 'unique_visitors', type: 'integer'),
            new OA\Property(property: 'p95', type: 'number'),
        ])),
        new OA\Property(property: 'top_paths', type: 'array', nullable: true, items: new OA\Items(type: 'object', properties: [
            new OA\Property(property: 'path', type: 'string'),
            new OA\Property(property: 'domain', type: 'string', nullable: true),
            new OA\Property(property: 'requests', type: 'integer'),
            new OA\Property(property: 'bytes_out', type: 'integer'),
            new OA\Property(property: 's4xx', type: 'integer'),
            new OA\Property(property: 's5xx', type: 'integer'),
            new OA\Property(property: 'p95', type: 'number'),
        ])),
        new OA\Property(
            property: 'breakdowns',
            type: 'object',
            nullable: true,
            description: 'Top values per dimension: country, city, referer, browser, os, device, protocol, cache, status, agent, ip, useragent. ip and useragent need the `read:sensitive` or `root` token ability. ip and city are left out when the server does not record IPs.',
            additionalProperties: new OA\AdditionalProperties(type: 'array', items: new OA\Items(type: 'object', properties: [
                new OA\Property(property: 'value', type: 'string'),
                new OA\Property(property: 'requests', type: 'integer'),
                new OA\Property(property: 'bytes_out', type: 'integer'),
                new OA\Property(property: 'lat', type: 'number', description: 'Only for city.'),
                new OA\Property(property: 'lon', type: 'number', description: 'Only for city.'),
            ])),
        ),
        new OA\Property(property: 'attribution', type: 'string', nullable: true, description: 'GeoIP data attribution to show with country and city data.'),
    ],
)]
class ResourceTrafficController extends Controller
{
    #[OA\Get(
        summary: 'Get application traffic',
        description: 'Get the traffic analytics of an application.',
        path: '/applications/{uuid}/traffic',
        operationId: 'get-application-traffic-by-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Applications'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the application.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'range', in: 'query', required: false, description: 'Time range of the traffic analytics.', schema: new OA\Schema(type: 'string', default: '24h', enum: ['24h', '7d', '30d'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Traffic analytics of the application.', content: new OA\JsonContent(ref: '#/components/schemas/ResourceTraffic')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
            new OA\Response(
                response: 502,
                description: 'Sentinel did not return traffic analytics.',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string', example: 'Failed to fetch traffic analytics from Sentinel.')], type: 'object'),
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

        return $this->traffic($request, $application);
    }

    #[OA\Get(
        summary: 'Get service traffic',
        description: 'Get the traffic analytics of a service.',
        path: '/services/{uuid}/traffic',
        operationId: 'get-service-traffic-by-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Services'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the service.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'range', in: 'query', required: false, description: 'Time range of the traffic analytics.', schema: new OA\Schema(type: 'string', default: '24h', enum: ['24h', '7d', '30d'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Traffic analytics of the service.', content: new OA\JsonContent(ref: '#/components/schemas/ResourceTraffic')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
            new OA\Response(
                response: 502,
                description: 'Sentinel did not return traffic analytics.',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string', example: 'Failed to fetch traffic analytics from Sentinel.')], type: 'object'),
            ),
        ],
    )]
    public function service(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $service = Service::whereRelation('environment.project.team', 'id', $teamId)->whereUuid($request->route('uuid'))->first();
        if (! $service) {
            return response()->json(['message' => 'Service not found.'], 404);
        }

        return $this->traffic($request, $service);
    }

    private function traffic(Request $request, Application|Service $model): JsonResponse
    {
        $this->authorize('view', $model);

        $validator = customApiValidator($request->query(), [
            'range' => 'sometimes|in:24h,7d,30d',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $range = (string) $request->query('range', '24h');
        $resource = TrafficResource::for($model);
        $server = $resource->server();

        if (! $server?->isTrafficAnalyticsEnabled()) {
            return response()->json(ResourceTrafficReport::disabled($range));
        }

        try {
            return response()->json(ResourceTrafficReport::build($resource, $server, $range, $request->attributes->get('can_read_sensitive', false) === true));
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Failed to fetch traffic analytics from Sentinel.'], 502);
        }
    }
}
