<?php

namespace App\Http\Controllers\Api;

use App\Actions\Service\DeployServiceApplication;
use App\Actions\Service\RestartServiceApplication;
use App\Actions\Service\StopServiceApplication;
use App\Actions\Service\UpdateServiceApplicationFromApi;
use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\ServiceApplication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

class ServiceApplicationsController extends Controller
{
    private function removeSensitiveData(ServiceApplication $serviceApplication): array
    {
        $serviceApplication->makeHidden([
            'id',
            'resourceable',
            'resourceable_id',
            'resourceable_type',
        ]);

        $serialized = serializeApiResponse($serviceApplication);

        if ($serialized instanceof Collection) {
            return $serialized->all();
        }

        return (array) $serialized;
    }

    private function resolveService(Request $request, int $teamId): ?Service
    {
        $uuid = $request->route('uuid');
        if (! $uuid) {
            return null;
        }

        return Service::whereRelation('environment.project.team', 'id', $teamId)
            ->whereUuid($uuid)
            ->first();
    }

    private function resolveServiceApplicationForService(Request $request, Service $service): ?ServiceApplication
    {
        $appUuid = $request->route('app_uuid');
        if (! $appUuid) {
            return null;
        }

        return $service->applications()
            ->where('uuid', $appUuid)
            ->with(['service.destination.server'])
            ->first();
    }

    private function swarmNotSupportedResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'This operation is not supported for Swarm servers yet.',
        ], 501);
    }

    #[OA\Get(
        summary: 'List service applications',
        description: 'List compose service applications (containers) for a single service.',
        path: '/services/{uuid}/applications',
        operationId: 'list-service-applications-by-service-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Service applications'],
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                in: 'path',
                description: 'Service UUID.',
                required: true,
                schema: new OA\Schema(type: 'string')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Service applications for this service.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'array',
                            items: new OA\Items(type: 'object')
                        )
                    ),
                ]
            ),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 404,
                ref: '#/components/responses/404',
            ),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $service = $this->resolveService($request, $teamId);
        if (! $service) {
            return response()->json(['message' => 'Service not found.'], 404);
        }

        $this->authorize('view', $service);

        $items = $service->applications()
            ->get()
            ->map(fn (ServiceApplication $sa) => $this->removeSensitiveData($sa));

        return response()->json($items);
    }

    #[OA\Get(
        summary: 'Get service application',
        description: 'Get a single compose service application by service UUID and application UUID.',
        path: '/services/{uuid}/applications/{app_uuid}',
        operationId: 'get-service-application-by-service-and-app-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Service applications'],
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                in: 'path',
                description: 'Service UUID.',
                required: true,
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'app_uuid',
                in: 'path',
                description: 'Service application UUID.',
                required: true,
                schema: new OA\Schema(type: 'string')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Service application.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(type: 'object')
                    ),
                ]
            ),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 404,
                ref: '#/components/responses/404',
            ),
        ]
    )]
    public function show(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $service = $this->resolveService($request, $teamId);
        if (! $service) {
            return response()->json(['message' => 'Service not found.'], 404);
        }

        $serviceApplication = $this->resolveServiceApplicationForService($request, $service);
        if (! $serviceApplication) {
            return response()->json(['message' => 'Service application not found.'], 404);
        }

        $this->authorize('view', $serviceApplication);

        return response()->json($this->removeSensitiveData($serviceApplication));
    }

    #[OA\Patch(
        summary: 'Update service application',
        description: 'Update fields for a compose service application. Use `url` for comma-separated public URLs (same rules as `urls[].url` on PATCH /services/{uuid}).',
        path: '/services/{uuid}/applications/{app_uuid}',
        operationId: 'patch-service-application-by-service-and-app-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Service applications'],
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                in: 'path',
                description: 'Service UUID.',
                required: true,
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'app_uuid',
                in: 'path',
                description: 'Service application UUID.',
                required: true,
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'force_domain_override',
                in: 'query',
                description: 'When true, allow duplicate URLs in the request and proceed despite domain conflicts (same as service PATCH).',
                required: false,
                schema: new OA\Schema(type: 'boolean', default: false)
            ),
        ],
        requestBody: new OA\RequestBody(
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    type: 'object',
                    properties: [
                        'url' => new OA\Property(
                            property: 'url',
                            type: 'string',
                            nullable: true,
                            description: 'Comma-separated list of URLs (e.g. "http://app.example.com:8080,https://app2.example.com"). Stored as fqdn.'
                        ),
                        'human_name' => new OA\Property(property: 'human_name', type: 'string', nullable: true),
                        'description' => new OA\Property(property: 'description', type: 'string', nullable: true),
                        'image' => new OA\Property(property: 'image', type: 'string', nullable: true),
                        'exclude_from_status' => new OA\Property(property: 'exclude_from_status', type: 'boolean', nullable: true),
                        'is_log_drain_enabled' => new OA\Property(property: 'is_log_drain_enabled', type: 'boolean', nullable: true),
                        'is_gzip_enabled' => new OA\Property(property: 'is_gzip_enabled', type: 'boolean', nullable: true),
                        'is_stripprefix_enabled' => new OA\Property(property: 'is_stripprefix_enabled', type: 'boolean', nullable: true),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Updated service application.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(type: 'object')
                    ),
                ]
            ),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 404,
                ref: '#/components/responses/404',
            ),
            new OA\Response(
                response: 409,
                description: 'Domain conflicts (unless force_domain_override).',
            ),
            new OA\Response(
                response: 422,
                ref: '#/components/responses/422',
            ),
        ]
    )]
    public function update(Request $request, UpdateServiceApplicationFromApi $updateServiceApplicationFromApi): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $return = validateIncomingRequest($request);
        if ($return instanceof JsonResponse) {
            return $return;
        }

        $service = $this->resolveService($request, $teamId);
        if (! $service) {
            return response()->json(['message' => 'Service not found.'], 404);
        }

        $serviceApplication = $this->resolveServiceApplicationForService($request, $service);
        if (! $serviceApplication) {
            return response()->json(['message' => 'Service application not found.'], 404);
        }

        $this->authorize('update', $serviceApplication);

        $payload = $request->json()->all();
        if (empty($payload)) {
            $payload = $request->request->all();
        }

        $allowedFields = [
            'url',
            'human_name',
            'description',
            'image',
            'exclude_from_status',
            'is_log_drain_enabled',
            'is_gzip_enabled',
            'is_stripprefix_enabled',
        ];

        $validationRules = [
            'url' => 'nullable|string',
            'human_name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'image' => 'nullable|string',
            'exclude_from_status' => 'sometimes|boolean',
            'is_log_drain_enabled' => 'sometimes|boolean',
            'is_gzip_enabled' => 'sometimes|boolean',
            'is_stripprefix_enabled' => 'sometimes|boolean',
        ];

        $validator = Validator::make($payload, $validationRules);

        $extraFields = array_diff(array_keys($payload), $allowedFields);
        if ($validator->fails() || ! empty($extraFields)) {
            $errors = $validator->errors();
            foreach ($extraFields as $field) {
                $errors->add($field, 'This field is not allowed.');
            }

            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $errors,
            ], 422);
        }

        $response = $updateServiceApplicationFromApi->execute($serviceApplication, $request, $teamId, $payload);
        if ($response instanceof JsonResponse) {
            return $response;
        }

        $serviceApplication->refresh();

        return response()->json($this->removeSensitiveData($serviceApplication));
    }

    #[OA\Get(
        summary: 'Get service application logs',
        description: 'Get Docker logs for a single compose service container.',
        path: '/services/{uuid}/applications/{app_uuid}/logs',
        operationId: 'get-service-application-logs-by-service-and-app-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Service applications'],
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                in: 'path',
                description: 'Service UUID.',
                required: true,
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'app_uuid',
                in: 'path',
                description: 'Service application UUID.',
                required: true,
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'lines',
                in: 'query',
                description: 'Number of lines to show from the end of the logs.',
                required: false,
                schema: new OA\Schema(type: 'integer', format: 'int32', default: 100)
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Logs.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'logs' => new OA\Property(property: 'logs', type: 'string'),
                            ]
                        )
                    ),
                ]
            ),
            new OA\Response(
                response: 400,
                ref: '#/components/responses/400',
            ),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 404,
                ref: '#/components/responses/404',
            ),
            new OA\Response(
                response: 501,
                description: 'Swarm not supported.',
            ),
        ]
    )]
    #[OA\Post(
        summary: 'Get service application logs',
        description: 'Get Docker logs for a single compose service container.',
        path: '/services/{uuid}/applications/{app_uuid}/logs',
        operationId: 'post-service-application-logs-by-service-and-app-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Service applications'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'app_uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'lines', in: 'query', required: false, schema: new OA\Schema(type: 'integer', format: 'int32', default: 100)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Logs.',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [new OA\Property(property: 'logs', type: 'string')],
                ),
            ),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 501, description: 'Swarm not supported.'),
        ]
    )]
    public function logs_by_uuid(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $service = $this->resolveService($request, $teamId);
        if (! $service) {
            return response()->json(['message' => 'Service not found.'], 404);
        }

        $serviceApplication = $this->resolveServiceApplicationForService($request, $service);
        if (! $serviceApplication) {
            return response()->json(['message' => 'Service application not found.'], 404);
        }

        $this->authorize('view', $serviceApplication);

        $server = $serviceApplication->service->destination->server;
        if ($server->isSwarm()) {
            return $this->swarmNotSupportedResponse();
        }

        if (! $server->isFunctional()) {
            return response()->json([
                'message' => 'Server is not functional.',
            ], 400);
        }

        $containerName = $serviceApplication->name.'-'.$serviceApplication->service->uuid;

        $status = getContainerStatus($server, $containerName);
        if ($status !== 'running') {
            return response()->json([
                'message' => 'Service application container is not running.',
            ], 400);
        }

        $lines = normalizeLogLines($request->query('lines'));
        $logs = getContainerLogs($server, $containerName, $lines);

        return response()->json([
            'logs' => $logs,
        ]);
    }

    #[OA\Get(
        summary: 'Start or redeploy service application container',
        description: 'Runs docker compose up for a single compose service (no-deps), optionally pulling the image and rebuilding.',
        path: '/services/{uuid}/applications/{app_uuid}/start',
        operationId: 'start-service-application-by-service-and-app-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Service applications'],
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                in: 'path',
                description: 'Service UUID.',
                required: true,
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'app_uuid',
                in: 'path',
                description: 'Service application UUID.',
                required: true,
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'force',
                in: 'query',
                description: 'When true, passes --build to docker compose up.',
                required: false,
                schema: new OA\Schema(type: 'boolean', default: false)
            ),
            new OA\Parameter(
                name: 'latest',
                in: 'query',
                description: 'When true, pulls the image for this compose service before up.',
                required: false,
                schema: new OA\Schema(type: 'boolean', default: false)
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Deploy request queued.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'message' => new OA\Property(property: 'message', type: 'string'),
                            ]
                        )
                    ),
                ]
            ),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 404,
                ref: '#/components/responses/404',
            ),
            new OA\Response(
                response: 501,
                description: 'Swarm not supported.',
            ),
        ]
    )]
    #[OA\Post(
        summary: 'Start or redeploy service application container',
        description: 'Runs docker compose up for a single compose service (no-deps), optionally pulling the image and rebuilding.',
        path: '/services/{uuid}/applications/{app_uuid}/start',
        operationId: 'post-start-service-application-by-service-and-app-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Service applications'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'app_uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'force', in: 'query', required: false, schema: new OA\Schema(type: 'boolean', default: false)),
            new OA\Parameter(name: 'latest', in: 'query', required: false, schema: new OA\Schema(type: 'boolean', default: false)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Deploy request queued.',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [new OA\Property(property: 'message', type: 'string')],
                ),
            ),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 501, description: 'Swarm not supported.'),
        ]
    )]
    public function action_start(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $service = $this->resolveService($request, $teamId);
        if (! $service) {
            return response()->json(['message' => 'Service not found.'], 404);
        }

        $serviceApplication = $this->resolveServiceApplicationForService($request, $service);
        if (! $serviceApplication) {
            return response()->json(['message' => 'Service application not found.'], 404);
        }

        $this->authorize('deploy', $serviceApplication);

        $server = $serviceApplication->service->destination->server;
        if ($server->isSwarm()) {
            return $this->swarmNotSupportedResponse();
        }

        if (! $server->isFunctional()) {
            return response()->json([
                'message' => 'Server is not functional.',
            ], 400);
        }

        $pullLatest = $request->boolean('latest', false);
        $forceRebuild = $request->boolean('force', false);

        DeployServiceApplication::dispatch($serviceApplication, $pullLatest, $forceRebuild);

        return response()->json([
            'message' => 'Service application deploy request queued.',
        ], 200);
    }

    #[OA\Get(
        summary: 'Restart service application container',
        description: 'Restarts a single compose service container (docker restart).',
        path: '/services/{uuid}/applications/{app_uuid}/restart',
        operationId: 'restart-service-application-by-service-and-app-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Service applications'],
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                in: 'path',
                description: 'Service UUID.',
                required: true,
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'app_uuid',
                in: 'path',
                description: 'Service application UUID.',
                required: true,
                schema: new OA\Schema(type: 'string')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Restart queued.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'message' => new OA\Property(property: 'message', type: 'string'),
                            ]
                        )
                    ),
                ]
            ),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 404,
                ref: '#/components/responses/404',
            ),
            new OA\Response(
                response: 501,
                description: 'Swarm not supported.',
            ),
        ]
    )]
    #[OA\Post(
        summary: 'Restart service application container',
        description: 'Restarts a single compose service container.',
        path: '/services/{uuid}/applications/{app_uuid}/restart',
        operationId: 'post-restart-service-application-by-service-and-app-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Service applications'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'app_uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Restart queued.',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [new OA\Property(property: 'message', type: 'string')],
                ),
            ),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 501, description: 'Swarm not supported.'),
        ]
    )]
    public function action_restart(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $service = $this->resolveService($request, $teamId);
        if (! $service) {
            return response()->json(['message' => 'Service not found.'], 404);
        }

        $serviceApplication = $this->resolveServiceApplicationForService($request, $service);
        if (! $serviceApplication) {
            return response()->json(['message' => 'Service application not found.'], 404);
        }

        $this->authorize('deploy', $serviceApplication);

        $server = $serviceApplication->service->destination->server;
        if ($server->isSwarm()) {
            return $this->swarmNotSupportedResponse();
        }

        if (! $server->isFunctional()) {
            return response()->json([
                'message' => 'Server is not functional.',
            ], 400);
        }

        RestartServiceApplication::dispatch($serviceApplication);

        return response()->json([
            'message' => 'Service application restart request queued.',
        ], 200);
    }

    #[OA\Get(
        summary: 'Stop service application container',
        description: 'Stops a single compose service container (docker stop).',
        path: '/services/{uuid}/applications/{app_uuid}/stop',
        operationId: 'stop-service-application-by-service-and-app-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Service applications'],
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                in: 'path',
                description: 'Service UUID.',
                required: true,
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'app_uuid',
                in: 'path',
                description: 'Service application UUID.',
                required: true,
                schema: new OA\Schema(type: 'string')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Stop queued.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'message' => new OA\Property(property: 'message', type: 'string'),
                            ]
                        )
                    ),
                ]
            ),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 404,
                ref: '#/components/responses/404',
            ),
            new OA\Response(
                response: 501,
                description: 'Swarm not supported.',
            ),
        ]
    )]
    #[OA\Post(
        summary: 'Stop service application container',
        description: 'Stops a single compose service container.',
        path: '/services/{uuid}/applications/{app_uuid}/stop',
        operationId: 'post-stop-service-application-by-service-and-app-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Service applications'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'app_uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Stop queued.',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [new OA\Property(property: 'message', type: 'string')],
                ),
            ),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 501, description: 'Swarm not supported.'),
        ]
    )]
    public function action_stop(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $service = $this->resolveService($request, $teamId);
        if (! $service) {
            return response()->json(['message' => 'Service not found.'], 404);
        }

        $serviceApplication = $this->resolveServiceApplicationForService($request, $service);
        if (! $serviceApplication) {
            return response()->json(['message' => 'Service application not found.'], 404);
        }

        $this->authorize('deploy', $serviceApplication);

        $server = $serviceApplication->service->destination->server;
        if ($server->isSwarm()) {
            return $this->swarmNotSupportedResponse();
        }

        if (! $server->isFunctional()) {
            return response()->json([
                'message' => 'Server is not functional.',
            ], 400);
        }

        StopServiceApplication::dispatch($serviceApplication);

        return response()->json([
            'message' => 'Service application stop request queued.',
        ], 200);
    }
}
