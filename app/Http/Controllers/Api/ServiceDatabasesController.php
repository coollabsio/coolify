<?php

namespace App\Http\Controllers\Api;

use App\Actions\Database\StartDatabaseProxy;
use App\Actions\Database\StopDatabaseProxy;
use App\Actions\Service\DeployServiceApplication;
use App\Actions\Service\RestartServiceApplication;
use App\Actions\Service\StopServiceApplication;
use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\ServiceDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

class ServiceDatabasesController extends Controller
{
    private function removeSensitiveData(ServiceDatabase $serviceDatabase): array
    {
        $serviceDatabase->makeHidden([
            'id',
            'service',
            'service_id',
            'resourceable',
            'resourceable_id',
            'resourceable_type',
        ]);

        $serialized = serializeApiResponse($serviceDatabase);

        if ($serialized instanceof Collection) {
            return $serialized->all();
        }

        return (array) $serialized;
    }

    private function resolveService(Request $request, int $teamId): ?Service
    {
        return Service::whereRelation('environment.project.team', 'id', $teamId)
            ->whereUuid($request->route('uuid'))
            ->first();
    }

    private function resolveServiceDatabase(Request $request, Service $service): ?ServiceDatabase
    {
        return $service->databases()
            ->where('uuid', $request->route('database_uuid'))
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
        summary: 'List service databases',
        description: 'List compose databases for a single service.',
        path: '/services/{uuid}/databases',
        operationId: 'list-service-databases-by-service-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Service databases'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', description: 'Service UUID.', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Service databases.', content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'object'))),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
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

        $databases = $service->databases()
            ->get()
            ->map(fn (ServiceDatabase $database) => $this->removeSensitiveData($database));

        return response()->json($databases);
    }

    #[OA\Get(
        summary: 'Get service database',
        description: 'Get a compose database by service UUID and database UUID.',
        path: '/services/{uuid}/databases/{database_uuid}',
        operationId: 'get-service-database-by-service-and-database-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Service databases'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', description: 'Service UUID.', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'database_uuid', in: 'path', description: 'Service database UUID.', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Service database.', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
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

        $serviceDatabase = $this->resolveServiceDatabase($request, $service);
        if (! $serviceDatabase) {
            return response()->json(['message' => 'Service database not found.'], 404);
        }

        $this->authorize('view', $serviceDatabase);

        return response()->json($this->removeSensitiveData($serviceDatabase));
    }

    #[OA\Patch(
        summary: 'Update service database',
        description: 'Update mutable fields for a compose service database.',
        path: '/services/{uuid}/databases/{database_uuid}',
        operationId: 'patch-service-database-by-service-and-database-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Service databases'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', description: 'Service UUID.', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'database_uuid', in: 'path', description: 'Service database UUID.', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(property: 'human_name', type: 'string', nullable: true),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'image', type: 'string'),
                    new OA\Property(property: 'exclude_from_status', type: 'boolean'),
                    new OA\Property(property: 'is_log_drain_enabled', type: 'boolean'),
                    new OA\Property(property: 'is_public', type: 'boolean'),
                    new OA\Property(property: 'public_port', type: 'integer', nullable: true, minimum: 1, maximum: 65535),
                    new OA\Property(property: 'public_port_timeout', type: 'integer', nullable: true, minimum: 1),
                ],
                additionalProperties: false,
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Updated service database.', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function update(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $invalidRequest = validateIncomingRequest($request);
        if ($invalidRequest instanceof JsonResponse) {
            return $invalidRequest;
        }

        $service = $this->resolveService($request, $teamId);
        if (! $service) {
            return response()->json(['message' => 'Service not found.'], 404);
        }

        $serviceDatabase = $this->resolveServiceDatabase($request, $service);
        if (! $serviceDatabase) {
            return response()->json(['message' => 'Service database not found.'], 404);
        }

        $this->authorize('update', $serviceDatabase);

        $payload = $request->json()->all();
        if (empty($payload)) {
            $payload = $request->request->all();
        }

        $allowedFields = [
            'human_name',
            'description',
            'image',
            'exclude_from_status',
            'is_log_drain_enabled',
            'is_public',
            'public_port',
            'public_port_timeout',
        ];
        $validator = Validator::make($payload, [
            'human_name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'image' => 'sometimes|string',
            'exclude_from_status' => 'sometimes|boolean',
            'is_log_drain_enabled' => 'sometimes|boolean',
            'is_public' => 'sometimes|boolean',
            'public_port' => 'nullable|integer|min:1|max:65535',
            'public_port_timeout' => 'nullable|integer|min:1',
        ]);

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

        $server = $serviceDatabase->service->destination->server;
        if (($payload['is_log_drain_enabled'] ?? false) && ! $server->isLogDrainEnabled()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => ['is_log_drain_enabled' => ['Log drain is not enabled on the server for this service.']],
            ], 422);
        }

        $isPublic = $payload['is_public'] ?? $serviceDatabase->is_public;
        $publicPort = $payload['public_port'] ?? $serviceDatabase->public_port;
        if ($isPublic && ! $publicPort) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => ['public_port' => ['A public port is required when the database is public.']],
            ], 422);
        }
        if ($isPublic && isPublicPortAlreadyUsed($server, $publicPort, $serviceDatabase->id)) {
            return response()->json(['message' => 'Public port already used by another database.'], 400);
        }

        $shouldStartProxy = ($payload['is_public'] ?? null) === true && ! $serviceDatabase->is_public;
        $shouldStopProxy = ($payload['is_public'] ?? null) === false && $serviceDatabase->is_public;

        $serviceDatabase->fill($payload);
        $serviceDatabase->save();
        $serviceDatabase->refresh();
        updateCompose($serviceDatabase);

        if ($shouldStartProxy) {
            StartDatabaseProxy::dispatch($serviceDatabase);
        } elseif ($shouldStopProxy) {
            StopDatabaseProxy::dispatch($serviceDatabase);
        }

        auditLog('api.service_database.updated', [
            'team_id' => $teamId,
            'service_uuid' => $service->uuid,
            'service_database_uuid' => $serviceDatabase->uuid,
            'changed_fields' => array_keys($payload),
        ]);

        return response()->json($this->removeSensitiveData($serviceDatabase));
    }

    #[OA\Get(
        summary: 'Get service database logs',
        description: 'Get Docker logs for a compose database container.',
        path: '/services/{uuid}/databases/{database_uuid}/logs',
        operationId: 'get-service-database-logs-by-service-and-database-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Service databases'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'database_uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'lines', in: 'query', required: false, schema: new OA\Schema(type: 'integer', format: 'int32', default: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Logs.', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'logs', type: 'string')])),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 501, description: 'Swarm not supported.'),
        ]
    )]
    public function logs(Request $request): JsonResponse
    {
        $resolved = $this->resolveDatabaseRequest($request, 'view');
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        [$serviceDatabase, $server] = $resolved;
        $containerName = $serviceDatabase->name.'-'.$serviceDatabase->service->uuid;
        if (getContainerStatus($server, $containerName) !== 'running') {
            return response()->json(['message' => 'Service database container is not running.'], 400);
        }

        $lines = normalizeLogLines($request->query('lines'));

        return response()->json([
            'logs' => getContainerLogs($server, $containerName, $lines),
        ]);
    }

    #[OA\Post(
        summary: 'Start or redeploy service database container',
        description: 'Run docker compose up for a single compose database.',
        path: '/services/{uuid}/databases/{database_uuid}/start',
        operationId: 'start-service-database-by-service-and-database-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Service databases'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'database_uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'force', in: 'query', required: false, schema: new OA\Schema(type: 'boolean', default: false)),
            new OA\Parameter(name: 'latest', in: 'query', required: false, schema: new OA\Schema(type: 'boolean', default: false)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Deploy request queued.', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string')])),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 501, description: 'Swarm not supported.'),
        ]
    )]
    public function start(Request $request): JsonResponse
    {
        $resolved = $this->resolveDatabaseRequest($request, 'deploy');
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        [$serviceDatabase] = $resolved;
        DeployServiceApplication::dispatch(
            $serviceDatabase,
            $request->boolean('latest'),
            $request->boolean('force'),
        );

        return response()->json(['message' => 'Service database deploy request queued.']);
    }

    #[OA\Post(
        summary: 'Restart service database container',
        description: 'Restart a compose database container.',
        path: '/services/{uuid}/databases/{database_uuid}/restart',
        operationId: 'restart-service-database-by-service-and-database-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Service databases'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'database_uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Restart queued.', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string')])),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 501, description: 'Swarm not supported.'),
        ]
    )]
    public function restart(Request $request): JsonResponse
    {
        $resolved = $this->resolveDatabaseRequest($request, 'deploy');
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        [$serviceDatabase] = $resolved;
        RestartServiceApplication::dispatch($serviceDatabase);

        return response()->json(['message' => 'Service database restart request queued.']);
    }

    #[OA\Post(
        summary: 'Stop service database container',
        description: 'Stop a compose database container.',
        path: '/services/{uuid}/databases/{database_uuid}/stop',
        operationId: 'stop-service-database-by-service-and-database-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Service databases'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'database_uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Stop queued.', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string')])),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 501, description: 'Swarm not supported.'),
        ]
    )]
    public function stop(Request $request): JsonResponse
    {
        $resolved = $this->resolveDatabaseRequest($request, 'deploy');
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        [$serviceDatabase] = $resolved;
        StopServiceApplication::dispatch($serviceDatabase);

        return response()->json(['message' => 'Service database stop request queued.']);
    }

    private function resolveDatabaseRequest(Request $request, string $ability): array|JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $service = $this->resolveService($request, $teamId);
        if (! $service) {
            return response()->json(['message' => 'Service not found.'], 404);
        }

        $serviceDatabase = $this->resolveServiceDatabase($request, $service);
        if (! $serviceDatabase) {
            return response()->json(['message' => 'Service database not found.'], 404);
        }

        $this->authorize($ability, $serviceDatabase);

        $server = $serviceDatabase->service->destination->server;
        if ($server->isSwarm()) {
            return $this->swarmNotSupportedResponse();
        }
        if (! $server->isFunctional()) {
            return response()->json(['message' => 'Server is not functional.'], 400);
        }

        return [$serviceDatabase, $server];
    }
}
