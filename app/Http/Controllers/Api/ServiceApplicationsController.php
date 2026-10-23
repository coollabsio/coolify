<?php

namespace App\Http\Controllers\Api;

use App\Actions\Service\DeployServiceApplication;
use App\Actions\Service\RestartServiceApplication;
use App\Actions\Service\StopServiceApplication;
use App\Actions\Service\UpdateServiceApplicationFromApi;
use App\Enums\RedirectTypes;
use App\Http\Controllers\Controller;
use App\Models\EnvironmentVariable;
use App\Models\ServerSetting;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Support\ValidationPatterns;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
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
            'service',
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
                        'noindex_domains' => new OA\Property(
                            property: 'noindex_domains',
                            type: 'array',
                            items: new OA\Items(type: 'string'),
                            description: 'The subset of the service application domains served with an X-Robots-Tag: noindex, nofollow response header, keeping them out of search engines. Entries that are not among the domains are ignored.',
                            nullable: true,
                        ),
                        'human_name' => new OA\Property(property: 'human_name', type: 'string', nullable: true),
                        'description' => new OA\Property(property: 'description', type: 'string', nullable: true),
                        'image' => new OA\Property(property: 'image', type: 'string', nullable: true),
                        'exclude_from_status' => new OA\Property(property: 'exclude_from_status', type: 'boolean', nullable: true),
                        'is_log_drain_enabled' => new OA\Property(property: 'is_log_drain_enabled', type: 'boolean', nullable: true),
                        'is_gzip_enabled' => new OA\Property(property: 'is_gzip_enabled', type: 'boolean', nullable: true),
                        'traefik_certificate_resolver' => new OA\Property(property: 'traefik_certificate_resolver', type: 'string', nullable: true, description: 'Traefik certificate resolver for this service application. Null uses the server setting, "none" uses no resolver.'),
                        'is_stripprefix_enabled' => new OA\Property(property: 'is_stripprefix_enabled', type: 'boolean', nullable: true),
                        'is_force_https_enabled' => new OA\Property(property: 'is_force_https_enabled', type: 'boolean', nullable: true),
                        'is_permanent_redirect_enabled' => new OA\Property(property: 'is_permanent_redirect_enabled', type: 'boolean', nullable: true, description: 'Use permanent redirects (301/308) instead of temporary (302/307) for the HTTP to HTTPS and www redirects. Defaults to false.'),
                        'redirect' => new OA\Property(property: 'redirect', type: 'string', enum: ['both', 'www', 'non-www'], description: 'How to handle www and non-www domains. "www" redirects non-www to www, "non-www" redirects www to non-www. Both need a www and a non-www domain in url.'),
                        'max_restart_count' => new OA\Property(property: 'max_restart_count', type: 'integer', minimum: 0, nullable: true, description: 'Maximum Docker restart count before Coolify stops the container. Set to 0 to disable the limit.'),
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
            'noindex_domains',
            'human_name',
            'description',
            'image',
            'exclude_from_status',
            'is_log_drain_enabled',
            'is_gzip_enabled',
            'traefik_certificate_resolver',
            'is_stripprefix_enabled',
            'is_force_https_enabled',
            'is_permanent_redirect_enabled',
            'redirect',
            'max_restart_count',
        ];

        $validationRules = [
            'url' => ValidationPatterns::applicationDomainRules(),
            'noindex_domains' => 'sometimes|array|nullable',
            'noindex_domains.*' => 'string',
            'human_name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'image' => 'nullable|string',
            'exclude_from_status' => 'sometimes|boolean',
            'is_log_drain_enabled' => 'sometimes|boolean',
            'is_gzip_enabled' => 'sometimes|boolean',
            'traefik_certificate_resolver' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:'.ServerSetting::TRAEFIK_CERTIFICATE_RESOLVER_PATTERN],
            'is_stripprefix_enabled' => 'sometimes|boolean',
            'is_force_https_enabled' => 'sometimes|boolean',
            'is_permanent_redirect_enabled' => 'sometimes|boolean',
            'redirect' => ['sometimes', Rule::enum(RedirectTypes::class)],
            'max_restart_count' => 'sometimes|integer|min:0',
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

        auditLog('api.service_application.updated', [
            'team_id' => $teamId,
            'service_uuid' => $service->uuid,
            'service_application_uuid' => $serviceApplication->uuid,
            'changed_fields' => array_keys($payload),
        ]);

        return response()->json($this->removeSensitiveData($serviceApplication));
    }

    #[OA\Get(
        summary: 'Get service application logs',
        description: 'Get Docker logs for a single compose service container. Requires the `read:sensitive` or `root` token ability.',
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
                description: 'Number of lines to show from the end of the logs. Use `all` to return all logs. `-1` remains available as a compatibility alias.',
                required: false,
                schema: new OA\Schema(oneOf: [
                    new OA\Schema(type: 'integer', format: 'int32', default: 100, minimum: -1, maximum: 10000),
                    new OA\Schema(type: 'string', enum: ['all']),
                ])
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
        description: 'Get Docker logs for a single compose service container. Requires the `read:sensitive` or `root` token ability.',
        path: '/services/{uuid}/applications/{app_uuid}/logs',
        operationId: 'post-service-application-logs-by-service-and-app-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Service applications'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'app_uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(
                name: 'lines',
                in: 'query',
                description: 'Number of lines to show from the end of the logs. Use `all` to return all logs. `-1` remains available as a compatibility alias.',
                required: false,
                schema: new OA\Schema(oneOf: [
                    new OA\Schema(type: 'integer', format: 'int32', default: 100, minimum: -1, maximum: 10000),
                    new OA\Schema(type: 'string', enum: ['all']),
                ]),
            ),
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
        $logs = sanitizeLogsForExport(
            getContainerLogs($server, $containerName, $lines),
            EnvironmentVariable::logRedactionSecretsFor($serviceApplication->service),
        );

        return response()->json([
            'logs' => $logs,
        ]);
    }

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
