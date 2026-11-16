<?php

namespace App\Http\Controllers\Api;

use App\Actions\Node\CreateClusterDatabaseWorkload;
use App\Actions\Node\ManageNodeDatabaseBackups;
use App\Actions\Node\UpdateNodeWorkloadSettings;
use App\Enums\NodeDatabaseType;
use App\Enums\NodeWorkloadAction;
use App\Http\Controllers\Api\Concerns\HandlesClusterResourcesApi;
use App\Http\Controllers\Controller;
use App\Models\NodeWorkload;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Rules\DockerImageFormat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Cluster databases (decisions 0010 and 0029): list, show, create for each engine, update,
 * delete, the lifecycle actions, and dump backups to S3. The credentials are environment
 * variables; their values show only for a token that may read sensitive data.
 */
class ClusterDatabasesController extends Controller
{
    use HandlesClusterResourcesApi;

    #[OA\Get(
        summary: 'List',
        description: 'List the cluster databases that the token can see.',
        path: '/cluster-databases',
        operationId: 'list-cluster-databases',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Databases'],
        responses: [
            new OA\Response(response: 200, description: 'The cluster databases.', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/ClusterDatabase'))),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
        ]
    )]
    public function index(): JsonResponse
    {
        $workloads = $this->clusterWorkloads('database')->with(['nodes', 'project', 'environment'])->orderBy('name')->get();

        return response()->json($workloads->map(fn (NodeWorkload $workload): array => $this->databaseResponse($workload))->values());
    }

    #[OA\Get(
        summary: 'Get',
        description: 'Get a cluster database by UUID.',
        path: '/cluster-databases/{uuid}',
        operationId: 'get-cluster-database-by-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Databases'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster database.', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The cluster database.', content: new OA\JsonContent(ref: '#/components/schemas/ClusterDatabase')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function show(Request $request): JsonResponse
    {
        $workload = $this->clusterWorkload($request, 'database');
        if ($workload === null) {
            return response()->json(['message' => 'Cluster database not found.'], 404);
        }
        $this->authorize('view', $workload);

        return response()->json($this->databaseResponse($workload));
    }

    #[OA\Post(
        summary: 'Create (PostgreSQL)',
        description: 'Create a PostgreSQL database on a cluster. Coolify generates the credentials. Set `cluster_uuid` for automatic placement, or `server_uuid` for one server of the cluster.',
        path: '/cluster-databases/postgresql',
        operationId: 'create-cluster-database-postgresql',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Databases'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ClusterDatabaseRequest')),
        responses: [
            new OA\Response(response: 201, description: 'The database is created.', content: new OA\JsonContent(ref: '#/components/schemas/ClusterResourceCreated')),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    #[OA\Post(
        summary: 'Create (MySQL)',
        description: 'Create a MySQL database on a cluster. Coolify generates the credentials. Set `cluster_uuid` for automatic placement, or `server_uuid` for one server of the cluster.',
        path: '/cluster-databases/mysql',
        operationId: 'create-cluster-database-mysql',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Databases'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ClusterDatabaseRequest')),
        responses: [
            new OA\Response(response: 201, description: 'The database is created.', content: new OA\JsonContent(ref: '#/components/schemas/ClusterResourceCreated')),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    #[OA\Post(
        summary: 'Create (MariaDB)',
        description: 'Create a MariaDB database on a cluster. Coolify generates the credentials. Set `cluster_uuid` for automatic placement, or `server_uuid` for one server of the cluster.',
        path: '/cluster-databases/mariadb',
        operationId: 'create-cluster-database-mariadb',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Databases'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ClusterDatabaseRequest')),
        responses: [
            new OA\Response(response: 201, description: 'The database is created.', content: new OA\JsonContent(ref: '#/components/schemas/ClusterResourceCreated')),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    #[OA\Post(
        summary: 'Create (MongoDB)',
        description: 'Create a MongoDB database on a cluster. Coolify generates the credentials. Set `cluster_uuid` for automatic placement, or `server_uuid` for one server of the cluster.',
        path: '/cluster-databases/mongodb',
        operationId: 'create-cluster-database-mongodb',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Databases'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ClusterDatabaseRequest')),
        responses: [
            new OA\Response(response: 201, description: 'The database is created.', content: new OA\JsonContent(ref: '#/components/schemas/ClusterResourceCreated')),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    #[OA\Post(
        summary: 'Create (Redis)',
        description: 'Create a Redis database on a cluster. Coolify generates the credentials. Set `cluster_uuid` for automatic placement, or `server_uuid` for one server of the cluster.',
        path: '/cluster-databases/redis',
        operationId: 'create-cluster-database-redis',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Databases'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ClusterDatabaseRequest')),
        responses: [
            new OA\Response(response: 201, description: 'The database is created.', content: new OA\JsonContent(ref: '#/components/schemas/ClusterResourceCreated')),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    #[OA\Post(
        summary: 'Create (KeyDB)',
        description: 'Create a KeyDB database on a cluster. Coolify generates the credentials. Set `cluster_uuid` for automatic placement, or `server_uuid` for one server of the cluster.',
        path: '/cluster-databases/keydb',
        operationId: 'create-cluster-database-keydb',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Databases'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ClusterDatabaseRequest')),
        responses: [
            new OA\Response(response: 201, description: 'The database is created.', content: new OA\JsonContent(ref: '#/components/schemas/ClusterResourceCreated')),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    #[OA\Post(
        summary: 'Create (Dragonfly)',
        description: 'Create a Dragonfly database on a cluster. Coolify generates the credentials. Set `cluster_uuid` for automatic placement, or `server_uuid` for one server of the cluster.',
        path: '/cluster-databases/dragonfly',
        operationId: 'create-cluster-database-dragonfly',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Databases'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ClusterDatabaseRequest')),
        responses: [
            new OA\Response(response: 201, description: 'The database is created.', content: new OA\JsonContent(ref: '#/components/schemas/ClusterResourceCreated')),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    #[OA\Post(
        summary: 'Create (ClickHouse)',
        description: 'Create a ClickHouse database on a cluster. Coolify generates the credentials. Set `cluster_uuid` for automatic placement, or `server_uuid` for one server of the cluster.',
        path: '/cluster-databases/clickhouse',
        operationId: 'create-cluster-database-clickhouse',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Databases'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ClusterDatabaseRequest')),
        responses: [
            new OA\Response(response: 201, description: 'The database is created.', content: new OA\JsonContent(ref: '#/components/schemas/ClusterResourceCreated')),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $type = NodeDatabaseType::tryFrom((string) $request->route('engine'));
        if ($type === null) {
            return response()->json(['message' => 'Unknown database engine.'], 404);
        }
        $invalid = $this->validateRequest($request, [
            ...$this->clusterTargetRules(),
            'image' => ['nullable', 'string', 'max:255', new DockerImageFormat],
        ]);
        if ($invalid !== null) {
            return $invalid;
        }
        $target = $this->clusterTarget($request);
        if ($target instanceof JsonResponse) {
            return $target;
        }
        [$project, $environment, $cluster, $node] = $target;
        $this->authorize('create', [NodeWorkload::class, $environment]);

        return $this->runClusterAction(function () use ($request, $project, $environment, $cluster, $node, $type): JsonResponse {
            $workload = CreateClusterDatabaseWorkload::run($project, $environment, $cluster, $type, $request->user(), $node, $request->input('image'));
            if (filled($request->input('name'))) {
                UpdateNodeWorkloadSettings::make()->details($workload, (string) $request->input('name'), $workload->description);
            }

            return $this->created($request, $workload);
        });
    }

    #[OA\Patch(
        summary: 'Update',
        description: 'Update a cluster database. `credentials` changes the credential variables, keyed by name (for example `POSTGRES_PASSWORD`). A locked credential cannot change. The container gets new values at the next deploy.',
        path: '/cluster-databases/{uuid}',
        operationId: 'update-cluster-database-by-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Databases'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster database.', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            type: 'object',
            properties: [
                'name' => new OA\Property(property: 'name', type: 'string'),
                'description' => new OA\Property(property: 'description', type: 'string'),
                'image' => new OA\Property(property: 'image', type: 'string'),
                'credentials' => new OA\Property(property: 'credentials', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string')),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'The updated cluster database.', content: new OA\JsonContent(ref: '#/components/schemas/ClusterDatabase')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function update(Request $request): JsonResponse
    {
        $workload = $this->clusterWorkload($request, 'database');
        if ($workload === null) {
            return response()->json(['message' => 'Cluster database not found.'], 404);
        }
        $this->authorize('update', $workload);
        $invalid = $this->validateRequest($request, ['name' => 'string', 'description' => 'string|nullable', 'image' => 'string', 'credentials' => 'array']);
        if ($invalid !== null) {
            return $invalid;
        }

        return $this->runClusterAction(function () use ($request, $workload): JsonResponse {
            UpdateNodeWorkloadSettings::make()->database(
                $workload,
                (string) $request->input('name', $workload->name),
                $request->has('description') ? $request->input('description') : $workload->description,
                (string) $request->input('image', $workload->revisions()->latest('id')->value('image')),
                $request->input('credentials', []),
            );

            return response()->json($this->databaseResponse($workload->refresh()));
        });
    }

    #[OA\Delete(
        summary: 'Delete',
        description: 'Delete a cluster database. Coolify removes its containers in the background.',
        path: '/cluster-databases/{uuid}',
        operationId: 'delete-cluster-database-by-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Databases'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster database.', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'delete_volumes', in: 'query', description: 'Also delete the data of its volumes.', required: false, schema: new OA\Schema(type: 'boolean', default: true)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The deletion is queued.', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string')])),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function destroy(Request $request): JsonResponse
    {
        return $this->deleteWorkload($request, 'database', 'Cluster database');
    }

    #[OA\Post(
        summary: 'Start',
        description: 'Start a cluster database. Like v4, this deploys it.',
        path: '/cluster-databases/{uuid}/start',
        operationId: 'start-cluster-database-by-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Databases'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster database.', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The deployment is queued.', content: new OA\JsonContent(ref: '#/components/schemas/ClusterDeploymentQueued')),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function start(Request $request): JsonResponse
    {
        return $this->deployWorkload($request, 'database', 'Cluster database');
    }

    #[OA\Post(
        summary: 'Stop',
        description: 'Stop a cluster database.',
        path: '/cluster-databases/{uuid}/stop',
        operationId: 'stop-cluster-database-by-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Databases'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster database.', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The stop is queued.', content: new OA\JsonContent(ref: '#/components/schemas/ClusterLifecycleQueued')),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function stop(Request $request): JsonResponse
    {
        return $this->lifecycle($request, 'database', NodeWorkloadAction::STOP);
    }

    #[OA\Post(
        summary: 'Restart',
        description: 'Restart a cluster database.',
        path: '/cluster-databases/{uuid}/restart',
        operationId: 'restart-cluster-database-by-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Databases'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster database.', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The restart is queued.', content: new OA\JsonContent(ref: '#/components/schemas/ClusterLifecycleQueued')),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function restart(Request $request): JsonResponse
    {
        return $this->lifecycle($request, 'database', NodeWorkloadAction::RESTART);
    }

    #[OA\Get(
        summary: 'List Backups',
        description: 'List the backup schedules of a cluster database.',
        path: '/cluster-databases/{uuid}/backups',
        operationId: 'list-cluster-database-backups',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Databases'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster database.', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The backup schedules.', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/ClusterDatabaseBackup'))),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function backups(Request $request): JsonResponse
    {
        $workload = $this->clusterWorkload($request, 'database');
        if ($workload === null) {
            return response()->json(['message' => 'Cluster database not found.'], 404);
        }
        $this->authorize('view', $workload);

        return response()->json(ManageNodeDatabaseBackups::schedulesOf($workload)->with('s3')->orderBy('id')->get()->map(fn (ScheduledDatabaseBackup $backup): array => $this->backupResponse($backup))->values());
    }

    #[OA\Post(
        summary: 'Create Backup Schedule',
        description: 'Create a schedule of dump backups to an S3 storage of the team.',
        path: '/cluster-databases/{uuid}/backups',
        operationId: 'create-cluster-database-backup',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Databases'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster database.', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            type: 'object',
            required: ['frequency', 's3_storage_uuid'],
            properties: [
                'frequency' => new OA\Property(property: 'frequency', type: 'string', description: 'A cron expression, or `daily`, `hourly`, and so on.'),
                's3_storage_uuid' => new OA\Property(property: 's3_storage_uuid', type: 'string'),
            ],
        )),
        responses: [
            new OA\Response(response: 201, description: 'The schedule is created.', content: new OA\JsonContent(ref: '#/components/schemas/ClusterDatabaseBackup')),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function createBackup(Request $request): JsonResponse
    {
        $workload = $this->clusterWorkload($request, 'database');
        if ($workload === null) {
            return response()->json(['message' => 'Cluster database not found.'], 404);
        }
        $this->authorize('update', $workload);
        $invalid = $this->validateRequest($request, ['frequency' => 'required|string', 's3_storage_uuid' => 'required|string']);
        if ($invalid !== null) {
            return $invalid;
        }
        $s3 = ManageNodeDatabaseBackups::storages($workload)->where('uuid', (string) $request->input('s3_storage_uuid'))->first();

        return $this->runClusterAction(fn (): JsonResponse => response()->json($this->backupResponse(
            ManageNodeDatabaseBackups::make()->createSchedule($workload, (string) $request->input('frequency'), $s3, $request->user(), 'api'),
        ), 201));
    }

    #[OA\Post(
        summary: 'Run Backup',
        description: 'Run a backup schedule now.',
        path: '/cluster-databases/{uuid}/backups/{backup_uuid}/run',
        operationId: 'run-cluster-database-backup',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Databases'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster database.', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'backup_uuid', in: 'path', description: 'UUID of the backup schedule.', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The backup is queued.', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string')])),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function runBackup(Request $request): JsonResponse
    {
        [$workload, $backup] = $this->backup($request);
        if ($backup === null) {
            return response()->json(['message' => 'Backup schedule not found.'], 404);
        }
        $this->authorize('update', $workload);

        return $this->runClusterAction(function () use ($workload, $backup): JsonResponse {
            ManageNodeDatabaseBackups::make()->runNow($workload, $backup, 'api');

            return response()->json(['message' => 'Backup queued.']);
        });
    }

    #[OA\Get(
        summary: 'List Backup Runs',
        description: 'List the runs of a backup schedule, newest first.',
        path: '/cluster-databases/{uuid}/backups/{backup_uuid}/executions',
        operationId: 'list-cluster-database-backup-executions',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Databases'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster database.', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'backup_uuid', in: 'path', description: 'UUID of the backup schedule.', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The runs.', content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'object', properties: [
                new OA\Property(property: 'uuid', type: 'string'),
                new OA\Property(property: 'status', type: 'string'),
                new OA\Property(property: 'message', type: 'string', nullable: true),
                new OA\Property(property: 'size', type: 'integer', nullable: true),
                new OA\Property(property: 'filename', type: 'string', nullable: true),
                new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                new OA\Property(property: 'finished_at', type: 'string', format: 'date-time', nullable: true),
            ]))),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function backupExecutions(Request $request): JsonResponse
    {
        [$workload, $backup] = $this->backup($request);
        if ($backup === null) {
            return response()->json(['message' => 'Backup schedule not found.'], 404);
        }
        $this->authorize('view', $workload);

        return response()->json($backup->executions()->latest('id')->limit(100)->get()->map(fn (ScheduledDatabaseBackupExecution $execution): array => [
            'uuid' => $execution->uuid,
            'status' => $execution->status,
            'message' => $execution->message,
            'size' => $execution->size,
            'filename' => $execution->filename,
            'created_at' => $execution->created_at,
            'finished_at' => $execution->finished_at,
        ])->values());
    }

    /** @return array{0: ?NodeWorkload, 1: ?ScheduledDatabaseBackup} */
    private function backup(Request $request): array
    {
        $workload = $this->clusterWorkload($request, 'database');
        $backup = $workload === null ? null : ManageNodeDatabaseBackups::schedulesOf($workload)->where('uuid', (string) $request->route('backup_uuid'))->first();

        return [$workload, $backup];
    }

    /** @return array<string, mixed> */
    private function backupResponse(ScheduledDatabaseBackup $backup): array
    {
        return [
            'uuid' => $backup->uuid,
            'frequency' => $backup->frequency,
            'enabled' => (bool) $backup->enabled,
            's3_storage_uuid' => $backup->s3?->uuid,
            'databases_to_backup' => $backup->databases_to_backup,
            'dump_all' => (bool) $backup->dump_all,
            'database_backup_retention_amount_s3' => $backup->database_backup_retention_amount_s3,
            'database_backup_retention_days_s3' => $backup->database_backup_retention_days_s3,
            'created_at' => $backup->created_at,
        ];
    }

    /**
     * The workload fields and the credentials. A credential value and the internal URL show only
     * for a token that may read sensitive data, and never when a credential is locked.
     *
     * @return array<string, mixed>
     */
    private function databaseResponse(NodeWorkload $workload): array
    {
        $type = $workload->database_type;
        $keys = array_column($type?->credentials() ?? [], 'key');
        $variables = $workload->environment_variables()->whereIn('key', $keys)->get()->keyBy('key');
        $locked = $variables->contains(fn ($variable): bool => (bool) $variable->is_shown_once);
        $sensitive = $this->canReadClusterSecrets($workload);
        $response = $this->workloadResponse($workload);

        return [
            ...$response,
            'credentials' => collect($keys)->mapWithKeys(fn (string $key): array => [$key => $sensitive && ! $variables->get($key)?->is_shown_once ? $variables->get($key)?->value : null])->all(),
            'internal_db_url' => $sensitive && ! $locked && $type !== null && $response['internal_hostname'] !== null
                ? $type->connectionUrl($variables->mapWithKeys(fn ($variable): array => [$variable->key => (string) $variable->value])->all(), $response['internal_hostname'])
                : null,
        ];
    }
}
