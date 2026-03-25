<?php

namespace App\Http\Controllers\Api;

use App\Actions\Database\RestartDatabase;
use App\Actions\Database\StartDatabase;
use App\Actions\Database\StartDatabaseProxy;
use App\Actions\Database\StopDatabase;
use App\Actions\Database\StopDatabaseProxy;
use App\Enums\NewDatabaseTypes;
use App\Http\Controllers\Controller;
use App\Jobs\DatabaseBackupJob;
use App\Jobs\DeleteResourceJob;
use App\Models\EnvironmentVariable;
use App\Models\LocalFileVolume;
use App\Models\LocalPersistentVolume;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\Server;
use App\Models\StandalonePostgresql;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class DatabasesController extends Controller
{
    private function removeSensitiveData($database)
    {
        $database->makeHidden([
            'id',
            'laravel_through_key',
        ]);
        if (request()->attributes->get('can_read_sensitive', false) === false) {
            $database->makeHidden([
                'internal_db_url',
                'external_db_url',
                'postgres_password',
                'dragonfly_password',
                'redis_password',
                'mongo_initdb_root_password',
                'keydb_password',
                'clickhouse_admin_password',
            ]);
        }

        return serializeApiResponse($database);
    }

    #[OA\Get(
        summary: 'List',
        description: 'List all databases.',
        path: '/databases',
        operationId: 'list-databases',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Databases'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Get all databases',
                content: new OA\JsonContent(
                    type: 'string',
                    example: 'Content is very complex. Will be implemented later.',
                ),
            ),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 400,
                ref: '#/components/responses/400',
            ),
        ]
    )]
    public function databases(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $projects = Project::where('team_id', $teamId)->get();
        $databases = collect();
        foreach ($projects as $project) {
            $databases = $databases->merge($project->databases());
        }

        $databaseIds = $databases->pluck('id')->toArray();

        $backupConfigs = ScheduledDatabaseBackup::ownedByCurrentTeamAPI($teamId)->with('latest_log')
            ->whereIn('database_id', $databaseIds)
            ->get()
            ->groupBy('database_id');

        $databases = $databases->map(function ($database) use ($backupConfigs) {
            $database->backup_configs = $backupConfigs->get($database->id, collect())->values();

            return $this->removeSensitiveData($database);
        });

        return response()->json($databases);
    }

    #[OA\Get(
        summary: 'Get',
        description: 'Get backups details by database UUID.',
        path: '/databases/{uuid}/backups',
        operationId: 'get-database-backups-by-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Databases'],
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                in: 'path',
                description: 'UUID of the database.',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                )
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Get all backups for a database',
                content: new OA\JsonContent(
                    type: 'string',
                    example: 'Content is very complex. Will be implemented later.',
                ),
            ),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 400,
                ref: '#/components/responses/400',
            ),
            new OA\Response(
                response: 404,
                ref: '#/components/responses/404',
            ),
        ]
    )]
    public function database_backup_details_uuid(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        if (! $request->uuid) {
            return response()->json(['message' => 'UUID is required.'], 404);
        }
        $database = queryDatabaseByUuidWithinTeam($request->uuid, $teamId);
        if (! $database) {
            return response()->json(['message' => 'Database not found.'], 404);
        }

        $this->authorize('view', $database);

        $backupConfig = ScheduledDatabaseBackup::ownedByCurrentTeamAPI($teamId)->with('executions')->where('database_id', $database->id)->get();

        return response()->json($backupConfig);
    }

    #[OA\Get(
        summary: 'Get',
        description: 'Get database by UUID.',
        path: '/databases/{uuid}',
        operationId: 'get-database-by-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Databases'],
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                in: 'path',
                description: 'UUID of the database.',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                )
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Get all databases',
                content: new OA\JsonContent(
                    type: 'string',
                    example: 'Content is very complex. Will be implemented later.',
                ),
            ),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 400,
                ref: '#/components/responses/400',
            ),
            new OA\Response(
                response: 404,
                ref: '#/components/responses/404',
            ),
        ]
    )]
    public function database_by_uuid(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        if (! $request->uuid) {
            return response()->json(['message' => 'UUID is required.'], 404);
        }
        $database = queryDatabaseByUuidWithinTeam($request->uuid, $teamId);
        if (! $database) {
            return response()->json(['message' => 'Database not found.'], 404);
        }

        $this->authorize('view', $database);

        return response()->json($this->removeSensitiveData($database));
    }

    #[OA\Patch(
        summary: 'Update',
        description: 'Update database by UUID.',
        path: '/databases/{uuid}',
        operationId: 'update-database-by-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Databases'],
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                in: 'path',
                description: 'UUID of the database.',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                )
            ),
        ],
        requestBody: new OA\RequestBody(
            description: 'Database data',
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    type: 'object',
                    properties: [
                        'name' => ['type' => 'string', 'description' => 'Name of the database'],
                        'description' => ['type' => 'string', 'description' => 'Description of the database'],
                        'image' => ['type' => 'string', 'description' => 'Docker Image of the database'],
                        'is_public' => ['type' => 'boolean', 'description' => 'Is the database public?'],
                        'public_port' => ['type' => 'integer', 'description' => 'Public port of the database'],
                        'limits_memory' => ['type' => 'string', 'description' => 'Memory limit of the database'],
                        'limits_memory_swap' => ['type' => 'string', 'description' => 'Memory swap limit of the database'],
                        'limits_memory_swappiness' => ['type' => 'integer', 'description' => 'Memory swappiness of the database'],
                        'limits_memory_reservation' => ['type' => 'string', 'description' => 'Memory reservation of the database'],
                        'limits_cpus' => ['type' => 'string', 'description' => 'CPU limit of the database'],
                        'limits_cpuset' => ['type' => 'string', 'description' => 'CPU set of the database'],
                        'limits_cpu_shares' => ['type' => 'integer', 'description' => 'CPU shares of the database'],
                        'postgres_user' => ['type' => 'string', 'description' => 'PostgreSQL user'],
                        'postgres_password' => ['type' => 'string', 'description' => 'PostgreSQL password'],
                        'postgres_db' => ['type' => 'string', 'description' => 'PostgreSQL database'],
                        'postgres_initdb_args' => ['type' => 'string', 'description' => 'PostgreSQL initdb args'],
                        'postgres_host_auth_method' => ['type' => 'string', 'description' => 'PostgreSQL host auth method'],
                        'postgres_conf' => ['type' => 'string', 'description' => 'PostgreSQL conf'],
                        'clickhouse_admin_user' => ['type' => 'string', 'description' => 'Clickhouse admin user'],
                        'clickhouse_admin_password' => ['type' => 'string', 'description' => 'Clickhouse admin password'],
                        'dragonfly_password' => ['type' => 'string', 'description' => 'DragonFly password'],
                        'redis_password' => ['type' => 'string', 'description' => 'Redis password'],
                        'redis_conf' => ['type' => 'string', 'description' => 'Redis conf'],
                        'keydb_password' => ['type' => 'string', 'description' => 'KeyDB password'],
                        'keydb_conf' => ['type' => 'string', 'description' => 'KeyDB conf'],
                        'mariadb_conf' => ['type' => 'string', 'description' => 'MariaDB conf'],
                        'mariadb_root_password' => ['type' => 'string', 'description' => 'MariaDB root password'],
                        'mariadb_user' => ['type' => 'string', 'description' => 'MariaDB user'],
                        'mariadb_password' => ['type' => 'string', 'description' => 'MariaDB password'],
                        'mariadb_database' => ['type' => 'string', 'description' => 'MariaDB database'],
                        'mongo_conf' => ['type' => 'string', 'description' => 'Mongo conf'],
                        'mongo_initdb_root_username' => ['type' => 'string', 'description' => 'Mongo initdb root username'],
                        'mongo_initdb_root_password' => ['type' => 'string', 'description' => 'Mongo initdb root password'],
                        'mongo_initdb_database' => ['type' => 'string', 'description' => 'Mongo initdb init database'],
                        'mysql_root_password' => ['type' => 'string', 'description' => 'MySQL root password'],
                        'mysql_password' => ['type' => 'string', 'description' => 'MySQL password'],
                        'mysql_user' => ['type' => 'string', 'description' => 'MySQL user'],
                        'mysql_database' => ['type' => 'string', 'description' => 'MySQL database'],
                        'mysql_conf' => ['type' => 'string', 'description' => 'MySQL conf'],
                    ],
                ),
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Database updated',
            ),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 400,
                ref: '#/components/responses/400',
            ),
            new OA\Response(
                response: 404,
                ref: '#/components/responses/404',
            ),
            new OA\Response(
                response: 422,
                ref: '#/components/responses/422',
            ),
        ]
    )]
    public function update_by_uuid(Request $request)
    {
        $allowedFields = ['name', 'description', 'image', 'public_port', 'is_public', 'instant_deploy', 'limits_memory', 'limits_memory_swap', 'limits_memory_swappiness', 'limits_memory_reservation', 'limits_cpus', 'limits_cpuset', 'limits_cpu_shares', 'postgres_user', 'postgres_password', 'postgres_db', 'postgres_initdb_args', 'postgres_host_auth_method', 'postgres_conf', 'clickhouse_admin_user', 'clickhouse_admin_password', 'dragonfly_password', 'redis_password', 'redis_conf', 'keydb_password', 'keydb_conf', 'mariadb_conf', 'mariadb_root_password', 'mariadb_user', 'mariadb_password', 'mariadb_database', 'mongo_conf', 'mongo_initdb_root_username', 'mongo_initdb_root_password', 'mongo_initdb_database', 'mysql_root_password', 'mysql_password', 'mysql_user', 'mysql_database', 'mysql_conf'];
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        // this check if the request is a valid json
        $return = validateIncomingRequest($request);
        if ($return instanceof \Illuminate\Http\JsonResponse) {
            return $return;
        }
        $validator = customApiValidator($request->all(), [
            'name' => 'string|max:255',
            'description' => 'string|nullable',
            'image' => 'string',
            'is_public' => 'boolean',
            'public_port' => 'numeric|nullable',
            'limits_memory' => 'string',
            'limits_memory_swap' => 'string',
            'limits_memory_swappiness' => 'numeric',
            'limits_memory_reservation' => 'string',
            'limits_cpus' => 'string',
            'limits_cpuset' => 'string|nullable',
            'limits_cpu_shares' => 'numeric',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }
        $uuid = $request->uuid;
        removeUnnecessaryFieldsFromRequest($request);
        $database = queryDatabaseByUuidWithinTeam($uuid, $teamId);
        if (! $database) {
            return response()->json(['message' => 'Database not found.'], 404);
        }

        $this->authorize('update', $database);

        if ($request->is_public && $request->public_port) {
            if (isPublicPortAlreadyUsed($database->destination->server, $request->public_port, $database->id)) {
                return response()->json(['message' => 'Public port already used by another database.'], 400);
            }
        }
        switch ($database->type()) {
            case 'standalone-postgresql':
                $allowedFields = ['name', 'description', 'image', 'public_port', 'is_public', 'instant_deploy', 'limits_memory', 'limits_memory_swap', 'limits_memory_swappiness', 'limits_memory_reservation', 'limits_cpus', 'limits_cpuset', 'limits_cpu_shares', 'postgres_user', 'postgres_password', 'postgres_db', 'postgres_initdb_args', 'postgres_host_auth_method', 'postgres_conf'];
                $validator = customApiValidator($request->all(), [
                    'postgres_user' => 'string',
                    'postgres_password' => 'string',
                    'postgres_db' => 'string',
                    'postgres_initdb_args' => 'string',
                    'postgres_host_auth_method' => 'string',
                    'postgres_conf' => 'string',
                ]);
                if ($request->has('postgres_conf')) {
                    if (! isBase64Encoded($request->postgres_conf)) {
                        return response()->json([
                            'message' => 'Validation failed.',
                            'errors' => [
                                'postgres_conf' => 'The postgres_conf should be base64 encoded.',
                            ],
                        ], 422);
                    }
                    $postgresConf = base64_decode($request->postgres_conf);
                    if (mb_detect_encoding($postgresConf, 'UTF-8', true) === false) {
                        return response()->json([
                            'message' => 'Validation failed.',
                            'errors' => [
                                'postgres_conf' => 'The postgres_conf should be base64 encoded.',
                            ],
                        ], 422);
                    }
                    $request->offsetSet('postgres_conf', $postgresConf);
                }
                break;
            case 'standalone-clickhouse':
                $allowedFields = ['name', 'description', 'image', 'public_port', 'is_public', 'instant_deploy', 'limits_memory', 'limits_memory_swap', 'limits_memory_swappiness', 'limits_memory_reservation', 'limits_cpus', 'limits_cpuset', 'limits_cpu_shares', 'clickhouse_admin_user', 'clickhouse_admin_password'];
                $validator = customApiValidator($request->all(), [
                    'clickhouse_admin_user' => 'string',
                    'clickhouse_admin_password' => 'string',
                ]);
                break;
            case 'standalone-dragonfly':
                $allowedFields = ['name', 'description', 'image', 'public_port', 'is_public', 'instant_deploy', 'limits_memory', 'limits_memory_swap', 'limits_memory_swappiness', 'limits_memory_reservation', 'limits_cpus', 'limits_cpuset', 'limits_cpu_shares', 'dragonfly_password'];
                $validator = customApiValidator($request->all(), [
                    'dragonfly_password' => 'string',
                ]);
                break;
            case 'standalone-redis':
                $allowedFields = ['name', 'description', 'image', 'public_port', 'is_public', 'instant_deploy', 'limits_memory', 'limits_memory_swap', 'limits_memory_swappiness', 'limits_memory_reservation', 'limits_cpus', 'limits_cpuset', 'limits_cpu_shares', 'redis_password', 'redis_conf'];
                $validator = customApiValidator($request->all(), [
                    'redis_password' => 'string',
                    'redis_conf' => 'string',
                ]);
                if ($request->has('redis_conf')) {
                    if (! isBase64Encoded($request->redis_conf)) {
                        return response()->json([
                            'message' => 'Validation failed.',
                            'errors' => [
                                'redis_conf' => 'The redis_conf should be base64 encoded.',
                            ],
                        ], 422);
                    }
                    $redisConf = base64_decode($request->redis_conf);
                    if (mb_detect_encoding($redisConf, 'UTF-8', true) === false) {
                        return response()->json([
                            'message' => 'Validation failed.',
                            'errors' => [
                                'redis_conf' => 'The redis_conf should be base64 encoded.',
                            ],
                        ], 422);
                    }
                    $request->offsetSet('redis_conf', $redisConf);
                }
                break;
            case 'standalone-keydb':
                $allowedFields = ['name', 'description', 'image', 'public_port', 'is_public', 'instant_deploy', 'limits_memory', 'limits_memory_swap', 'limits_memory_swappiness', 'limits_memory_reservation', 'limits_cpus', 'limits_cpuset', 'limits_cpu_shares', 'keydb_password', 'keydb_conf'];
                $validator = customApiValidator($request->all(), [
                    'keydb_password' => 'string',
                    'keydb_conf' => 'string',
                ]);
                if ($request->has('keydb_conf')) {
                    if (! isBase64Encoded($request->keydb_conf)) {
                        return response()->json([
                            'message' => 'Validation failed.',
                            'errors' => [
                                'keydb_conf' => 'The keydb_conf should be base64 encoded.',
                            ],
                        ], 422);
                    }
                    $keydbConf = base64_decode($request->keydb_conf);
                    if (mb_detect_encoding($keydbConf, 'UTF-8', true) === false) {
                        return response()->json([
                            'message' => 'Validation failed.',
                            'errors' => [
                                'keydb_conf' => 'The keydb_conf should be base64 encoded.',
                            ],
                        ], 422);
                    }
                    $request->offsetSet('keydb_conf', $keydbConf);
                }
                break;
            case 'standalone-mariadb':
                $allowedFields = ['name', 'description', 'image', 'public_port', 'is_public', 'instant_deploy', 'limits_memory', 'limits_memory_swap', 'limits_memory_swappiness', 'limits_memory_reservation', 'limits_cpus', 'limits_cpuset', 'limits_cpu_shares', 'mariadb_conf', 'mariadb_root_password', 'mariadb_user', 'mariadb_password', 'mariadb_database'];
                $validator = customApiValidator($request->all(), [
                    'mariadb_conf' => 'string',
                    'mariadb_root_password' => 'string',
                    'mariadb_user' => 'string',
                    'mariadb_password' => 'string',
                    'mariadb_database' => 'string',
                ]);
                if ($request->has('mariadb_conf')) {
                    if (! isBase64Encoded($request->mariadb_conf)) {
                        return response()->json([
                            'message' => 'Validation failed.',
                            'errors' => [
                                'mariadb_conf' => 'The mariadb_conf should be base64 encoded.',
                            ],
                        ], 422);
                    }
                    $mariadbConf = base64_decode($request->mariadb_conf);
                    if (mb_detect_encoding($mariadbConf, 'UTF-8', true) === false) {
                        return response()->json([
                            'message' => 'Validation failed.',
                            'errors' => [
                                'mariadb_conf' => 'The mariadb_conf should be base64 encoded.',
                            ],
                        ], 422);
                    }
                    $request->offsetSet('mariadb_conf', $mariadbConf);
                }
                break;
            case 'standalone-mongodb':
                $allowedFields = ['name', 'description', 'image', 'public_port', 'is_public', 'instant_deploy', 'limits_memory', 'limits_memory_swap', 'limits_memory_swappiness', 'limits_memory_reservation', 'limits_cpus', 'limits_cpuset', 'limits_cpu_shares', 'mongo_conf', 'mongo_initdb_root_username', 'mongo_initdb_root_password', 'mongo_initdb_database'];
                $validator = customApiValidator($request->all(), [
                    'mongo_conf' => 'string',
                    'mongo_initdb_root_username' => 'string',
                    'mongo_initdb_root_password' => 'string',
                    'mongo_initdb_database' => 'string',
                ]);
                if ($request->has('mongo_conf')) {
                    if (! isBase64Encoded($request->mongo_conf)) {
                        return response()->json([
                            'message' => 'Validation failed.',
                            'errors' => [
                                'mongo_conf' => 'The mongo_conf should be base64 encoded.',
                            ],
                        ], 422);
                    }
                    $mongoConf = base64_decode($request->mongo_conf);
                    if (mb_detect_encoding($mongoConf, 'UTF-8', true) === false) {
                        return response()->json([
                            'message' => 'Validation failed.',
                            'errors' => [
                                'mongo_conf' => 'The mongo_conf should be base64 encoded.',
                            ],
                        ], 422);
                    }
                    $request->offsetSet('mongo_conf', $mongoConf);
                }

                break;
            case 'standalone-mysql':
                $allowedFields = ['name', 'description', 'image', 'public_port', 'is_public', 'instant_deploy', 'limits_memory', 'limits_memory_swap', 'limits_memory_swappiness', 'limits_memory_reservation', 'limits_cpus', 'limits_cpuset', 'limits_cpu_shares', 'mysql_root_password', 'mysql_password', 'mysql_user', 'mysql_database', 'mysql_conf'];
                $validator = customApiValidator($request->all(), [
                    'mysql_root_password' => 'string',
                    'mysql_password' => 'string',
                    'mysql_user' => 'string',
                    'mysql_database' => 'string',
                    'mysql_conf' => 'string',
                ]);
                if ($request->has('mysql_conf')) {
                    if (! isBase64Encoded($request->mysql_conf)) {
                        return response()->json([
                            'message' => 'Validation failed.',
                            'errors' => [
                                'mysql_conf' => 'The mysql_conf should be base64 encoded.',
                            ],
                        ], 422);
                    }
                    $mysqlConf = base64_decode($request->mysql_conf);
                    if (mb_detect_encoding($mysqlConf, 'UTF-8', true) === false) {
                        return response()->json([
                            'message' => 'Validation failed.',
                            'errors' => [
                                'mysql_conf' => 'The mysql_conf should be base64 encoded.',
                            ],
                        ], 422);
                    }
                    $request->offsetSet('mysql_conf', $mysqlConf);
                }
                break;
        }
        $extraFields = array_diff(array_keys($request->all()), $allowedFields);
        if ($validator->fails() || ! empty($extraFields)) {
            $errors = $validator->errors();
            if (! empty($extraFields)) {
                foreach ($extraFields as $field) {
                    $errors->add($field, 'This field is not allowed.');
                }
            }

            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $errors,
            ], 422);
        }
        $whatToDoWithDatabaseProxy = null;
        if ($request->is_public === false && $database->is_public === true) {
            $whatToDoWithDatabaseProxy = 'stop';
        }
        if ($request->is_public === true && $request->public_port && $database->is_public === false) {
            $whatToDoWithDatabaseProxy = 'start';
        }

        // Only update database fields, not backup configuration
        $database->update($request->only($allowedFields));

        if ($whatToDoWithDatabaseProxy === 'start') {
            StartDatabaseProxy::dispatch($database);
        } elseif ($whatToDoWithDatabaseProxy === 'stop') {
            StopDatabaseProxy::dispatch($database);
        }

        return response()->json([
            'message' => 'Database updated.',
        ]);
    }

    #[OA\Post(
        summary: 'Create Backup',
        description: 'Create a new scheduled backup configuration for a database',
        path: '/databases/{uuid}/backups',
        operationId: 'create-database-backup',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Databases'],
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                in: 'path',
                description: 'UUID of the database.',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                )
            ),
        ],
        requestBody: new OA\RequestBody(
            description: 'Backup configuration data',
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    type: 'object',
                    required: ['frequency'],
                    properties: [
                        'frequency' => ['type' => 'string', 'description' => 'Backup frequency (cron expression or: every_minute, hourly, daily, weekly, monthly, yearly)'],
                        'enabled' => ['type' => 'boolean', 'description' => 'Whether the backup is enabled', 'default' => true],
                        'save_s3' => ['type' => 'boolean', 'description' => 'Whether to save backups to S3', 'default' => false],
                        's3_storage_uuid' => ['type' => 'string', 'description' => 'S3 storage UUID (required if save_s3 is true)'],
                        'databases_to_backup' => ['type' => 'string', 'description' => 'Comma separated list of databases to backup'],
                        'dump_all' => ['type' => 'boolean', 'description' => 'Whether to dump all databases', 'default' => false],
                        'backup_now' => ['type' => 'boolean', 'description' => 'Whether to trigger backup immediately after creation'],
                        'database_backup_retention_amount_locally' => ['type' => 'integer', 'description' => 'Number of backups to retain locally'],
                        'database_backup_retention_days_locally' => ['type' => 'integer', 'description' => 'Number of days to retain backups locally'],
                        'database_backup_retention_max_storage_locally' => ['type' => 'integer', 'description' => 'Max storage (MB) for local backups'],
                        'database_backup_retention_amount_s3' => ['type' => 'integer', 'description' => 'Number of backups to retain in S3'],
                        'database_backup_retention_days_s3' => ['type' => 'integer', 'description' => 'Number of days to retain backups in S3'],
                        'database_backup_retention_max_storage_s3' => ['type' => 'integer', 'description' => 'Max storage (MB) for S3 backups'],
                    ],
                ),
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Backup configuration created successfully',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        'uuid' => ['type' => 'string', 'format' => 'uuid', 'example' => '550e8400-e29b-41d4-a716-446655440000'],
                        'message' => ['type' => 'string', 'example' => 'Backup configuration created successfully.'],
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 400,
                ref: '#/components/responses/400',
            ),
            new OA\Response(
                response: 404,
                ref: '#/components/responses/404',
            ),
            new OA\Response(
                response: 422,
                ref: '#/components/responses/422',
            ),
        ]
    )]
    public function create_backup(Request $request)
    {
        $backupConfigFields = ['save_s3', 'enabled', 'dump_all', 'frequency', 'databases_to_backup', 'database_backup_retention_amount_locally', 'database_backup_retention_days_locally', 'database_backup_retention_max_storage_locally', 'database_backup_retention_amount_s3', 'database_backup_retention_days_s3', 'database_backup_retention_max_storage_s3', 's3_storage_uuid'];

        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        // Validate incoming request is valid JSON
        $return = validateIncomingRequest($request);
        if ($return instanceof \Illuminate\Http\JsonResponse) {
            return $return;
        }

        $validator = customApiValidator($request->all(), [
            'frequency' => 'required|string',
            'enabled' => 'boolean',
            'save_s3' => 'boolean',
            'dump_all' => 'boolean',
            'backup_now' => 'boolean|nullable',
            's3_storage_uuid' => 'string|exists:s3_storages,uuid|nullable',
            'databases_to_backup' => 'string|nullable',
            'database_backup_retention_amount_locally' => 'integer|min:0',
            'database_backup_retention_days_locally' => 'integer|min:0',
            'database_backup_retention_max_storage_locally' => 'integer|min:0',
            'database_backup_retention_amount_s3' => 'integer|min:0',
            'database_backup_retention_days_s3' => 'integer|min:0',
            'database_backup_retention_max_storage_s3' => 'integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        if (! $request->uuid) {
            return response()->json(['message' => 'UUID is required.'], 404);
        }

        $uuid = $request->uuid;
        $database = queryDatabaseByUuidWithinTeam($uuid, $teamId);
        if (! $database) {
            return response()->json(['message' => 'Database not found.'], 404);
        }

        $this->authorize('manageBackups', $database);

        // Validate frequency is a valid cron expression
        $isValid = validate_cron_expression($request->frequency);
        if (! $isValid) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => ['frequency' => ['Invalid cron expression or frequency format.']],
            ], 422);
        }

        // Validate S3 storage if save_s3 is true
        if ($request->boolean('save_s3') && ! $request->filled('s3_storage_uuid')) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => ['s3_storage_uuid' => ['The s3_storage_uuid field is required when save_s3 is true.']],
            ], 422);
        }

        if ($request->filled('s3_storage_uuid')) {
            $existsInTeam = S3Storage::ownedByCurrentTeam()->where('uuid', $request->s3_storage_uuid)->exists();
            if (! $existsInTeam) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => ['s3_storage_uuid' => ['The selected S3 storage is invalid for this team.']],
                ], 422);
            }
        }

        // Check for extra fields
        $extraFields = array_diff(array_keys($request->all()), $backupConfigFields, ['backup_now']);
        if (! empty($extraFields)) {
            $errors = $validator->errors();
            foreach ($extraFields as $field) {
                $errors->add($field, 'This field is not allowed.');
            }

            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $errors,
            ], 422);
        }

        $backupData = $request->only($backupConfigFields);

        // Convert s3_storage_uuid to s3_storage_id
        if (isset($backupData['s3_storage_uuid'])) {
            $s3Storage = S3Storage::ownedByCurrentTeam()->where('uuid', $backupData['s3_storage_uuid'])->first();
            if ($s3Storage) {
                $backupData['s3_storage_id'] = $s3Storage->id;
            } elseif ($request->boolean('save_s3')) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => ['s3_storage_uuid' => ['The selected S3 storage is invalid for this team.']],
                ], 422);
            }
            unset($backupData['s3_storage_uuid']);
        }

        // Set default databases_to_backup based on database type if not provided
        if (! isset($backupData['databases_to_backup']) || empty($backupData['databases_to_backup'])) {
            if ($database->type() === 'standalone-postgresql') {
                $backupData['databases_to_backup'] = $database->postgres_db;
            } elseif ($database->type() === 'standalone-mysql') {
                $backupData['databases_to_backup'] = $database->mysql_database;
            } elseif ($database->type() === 'standalone-mariadb') {
                $backupData['databases_to_backup'] = $database->mariadb_database;
            }
        }

        // Add required fields
        $backupData['database_id'] = $database->id;
        $backupData['database_type'] = $database->getMorphClass();
        $backupData['team_id'] = $teamId;

        // Set defaults
        if (! isset($backupData['enabled'])) {
            $backupData['enabled'] = true;
        }

        $backupConfig = ScheduledDatabaseBackup::create($backupData);

        // Trigger immediate backup if requested
        if ($request->backup_now) {
            dispatch(new DatabaseBackupJob($backupConfig));
        }

        return response()->json([
            'uuid' => $backupConfig->uuid,
            'message' => 'Backup configuration created successfully.',
        ], 201);
    }

    #[OA\Patch(
        summary: 'Update',
        description: 'Update a specific backup configuration for a given database, identified by its UUID and the backup ID',
        path: '/databases/{uuid}/backups/{scheduled_backup_uuid}',
        operationId: 'update-database-backup',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Databases'],
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                in: 'path',
                description: 'UUID of the database.',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                )
            ),
            new OA\Parameter(
                name: 'scheduled_backup_uuid',
                in: 'path',
                description: 'UUID of the backup configuration.',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                )
            ),
        ],
        requestBody: new OA\RequestBody(
            description: 'Database backup configuration data',
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    type: 'object',
                    properties: [
                        'save_s3' => ['type' => 'boolean', 'description' => 'Whether data is saved in s3 or not'],
                        's3_storage_uuid' => ['type' => 'string', 'description' => 'S3 storage UUID'],
                        'backup_now' => ['type' => 'boolean', 'description' => 'Whether to take a backup now or not'],
                        'enabled' => ['type' => 'boolean', 'description' => 'Whether the backup is enabled or not'],
                        'databases_to_backup' => ['type' => 'string', 'description' => 'Comma separated list of databases to backup'],
                        'dump_all' => ['type' => 'boolean', 'description' => 'Whether all databases are dumped or not'],
                        'frequency' => ['type' => 'string', 'description' => 'Frequency of the backup'],
                        'database_backup_retention_amount_locally' => ['type' => 'integer', 'description' => 'Retention amount of the backup locally'],
                        'database_backup_retention_days_locally' => ['type' => 'integer', 'description' => 'Retention days of the backup locally'],
                        'database_backup_retention_max_storage_locally' => ['type' => 'integer', 'description' => 'Max storage of the backup locally'],
                        'database_backup_retention_amount_s3' => ['type' => 'integer', 'description' => 'Retention amount of the backup in s3'],
                        'database_backup_retention_days_s3' => ['type' => 'integer', 'description' => 'Retention days of the backup in s3'],
                        'database_backup_retention_max_storage_s3' => ['type' => 'integer', 'description' => 'Max storage of the backup in S3'],
                    ],
                ),
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Database backup configuration updated',
            ),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 400,
                ref: '#/components/responses/400',
            ),
            new OA\Response(
                response: 404,
                ref: '#/components/responses/404',
            ),
            new OA\Response(
                response: 422,
                ref: '#/components/responses/422',
            ),
        ]
    )]
    public function update_backup(Request $request)
    {
        $backupConfigFields = ['save_s3', 'enabled', 'dump_all', 'frequency', 'databases_to_backup', 'database_backup_retention_amount_locally', 'database_backup_retention_days_locally', 'database_backup_retention_max_storage_locally', 'database_backup_retention_amount_s3', 'database_backup_retention_days_s3', 'database_backup_retention_max_storage_s3', 's3_storage_uuid'];

        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        // this check if the request is a valid json
        $return = validateIncomingRequest($request);
        if ($return instanceof \Illuminate\Http\JsonResponse) {
            return $return;
        }
        $validator = customApiValidator($request->all(), [
            'save_s3' => 'boolean',
            'backup_now' => 'boolean|nullable',
            'enabled' => 'boolean',
            'dump_all' => 'boolean',
            's3_storage_uuid' => 'string|exists:s3_storages,uuid|nullable',
            'databases_to_backup' => 'string|nullable',
            'frequency' => 'string|in:every_minute,hourly,daily,weekly,monthly,yearly',
            'database_backup_retention_amount_locally' => 'integer|min:0',
            'database_backup_retention_days_locally' => 'integer|min:0',
            'database_backup_retention_max_storage_locally' => 'integer|min:0',
            'database_backup_retention_amount_s3' => 'integer|min:0',
            'database_backup_retention_days_s3' => 'integer|min:0',
            'database_backup_retention_max_storage_s3' => 'integer|min:0',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        if (! $request->uuid) {
            return response()->json(['message' => 'UUID is required.'], 404);
        }

        // Validate scheduled_backup_uuid is provided
        if (! $request->scheduled_backup_uuid) {
            return response()->json(['message' => 'Scheduled backup UUID is required.'], 400);
        }

        $uuid = $request->uuid;
        removeUnnecessaryFieldsFromRequest($request);
        $database = queryDatabaseByUuidWithinTeam($uuid, $teamId);
        if (! $database) {
            return response()->json(['message' => 'Database not found.'], 404);
        }

        $this->authorize('update', $database);

        if ($request->boolean('save_s3') && ! $request->filled('s3_storage_uuid')) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => ['s3_storage_uuid' => ['The s3_storage_uuid field is required when save_s3 is true.']],
            ], 422);
        }
        if ($request->filled('s3_storage_uuid')) {
            $existsInTeam = S3Storage::ownedByCurrentTeam()->where('uuid', $request->s3_storage_uuid)->exists();
            if (! $existsInTeam) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => ['s3_storage_uuid' => ['The selected S3 storage is invalid for this team.']],
                ], 422);
            }
        }

        $backupConfig = ScheduledDatabaseBackup::ownedByCurrentTeamAPI($teamId)->where('database_id', $database->id)
            ->where('uuid', $request->scheduled_backup_uuid)
            ->first();
        if (! $backupConfig) {
            return response()->json(['message' => 'Backup config not found.'], 404);
        }

        $extraFields = array_diff(array_keys($request->all()), $backupConfigFields, ['backup_now']);
        if (! empty($extraFields)) {
            $errors = $validator->errors();
            foreach ($extraFields as $field) {
                $errors->add($field, 'This field is not allowed.');
            }

            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $errors,
            ], 422);
        }

        $backupData = $request->only($backupConfigFields);

        // Convert s3_storage_uuid to s3_storage_id
        if (isset($backupData['s3_storage_uuid'])) {
            $s3Storage = S3Storage::ownedByCurrentTeam()->where('uuid', $backupData['s3_storage_uuid'])->first();
            if ($s3Storage) {
                $backupData['s3_storage_id'] = $s3Storage->id;
            } elseif ($request->boolean('save_s3')) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => ['s3_storage_uuid' => ['The selected S3 storage is invalid for this team.']],
                ], 422);
            }
            unset($backupData['s3_storage_uuid']);
        }

        $backupConfig->update($backupData);

        if ($request->backup_now) {
            dispatch(new DatabaseBackupJob($backupConfig));
        }

        return response()->json([
            'message' => 'Database backup configuration updated',
        ]);
    }

    #[OA\Post(
        summary: 'Create (PostgreSQL)',
        description: 'Create a new PostgreSQL database.',
        path: '/databases/postgresql',
        operationId: 'create-database-postgresql',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Databases'],

        requestBody: new OA\RequestBody(
            description: 'Database data',
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    type: 'object',
                    required: ['server_uuid', 'project_uuid', 'environment_name', 'environment_uuid'],
                    properties: [
                        'server_uuid' => ['type' => 'string', 'description' => 'UUID of the server'],
                        'project_uuid' => ['type' => 'string', 'description' => 'UUID of the project'],
                        'environment_name' => ['type' => 'string', 'description' => 'Name of the environment. You need to provide at least one of environment_name or environment_uuid.'],
                        'environment_uuid' => ['type' => 'string', 'description' => 'UUID of the environment. You need to provide at least one of environment_name or environment_uuid.'],
                        'postgres_user' => ['type' => 'string', 'description' => 'PostgreSQL user'],
                        'postgres_password' => ['type' => 'string', 'description' => 'PostgreSQL password'],
                        'postgres_db' => ['type' => 'string', 'description' => 'PostgreSQL database'],
                        'postgres_initdb_args' => ['type' => 'string', 'description' => 'PostgreSQL initdb args'],
                        'postgres_host_auth_method' => ['type' => 'string', 'description' => 'PostgreSQL host auth method'],
                        'postgres_conf' => ['type' => 'string', 'description' => 'PostgreSQL conf'],
                        'destination_uuid' => ['type' => 'string', 'description' => 'UUID of the destination if the server has multiple destinations'],
                        'name' => ['type' => 'string', 'description' => 'Name of the database'],
                        'description' => ['type' => 'string', 'description' => 'Description of the database'],
                        'image' => ['type' => 'string', 'description' => 'Docker Image of the database'],
                        'is_public' => ['type' => 'boolean', 'description' => 'Is the database public?'],
                        'public_port' => ['type' => 'integer', 'description' => 'Public port of the database'],
                        'limits_memory' => ['type' => 'string', 'description' => 'Memory limit of the database'],
                        'limits_memory_swap' => ['type' => 'string', 'description' => 'Memory swap limit of the database'],
                        'limits_memory_swappiness' => ['type' => 'integer', 'description' => 'Memory swappiness of the database'],
                        'limits_memory_reservation' => ['type' => 'string', 'description' => 'Memory reservation of the database'],
                        'limits_cpus' => ['type' => 'string', 'description' => 'CPU limit of the database'],
                        'limits_cpuset' => ['type' => 'string', 'description' => 'CPU set of the database'],
                        'limits_cpu_shares' => ['type' => 'integer', 'description' => 'CPU shares of the database'],
                        'instant_deploy' => ['type' => 'boolean', 'description' => 'Instant deploy the database'],
                    ],
                ),
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Database updated',
            ),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 400,
                ref: '#/components/responses/400',
            ),
            new OA\Response(
                response: 422,
                ref: '#/components/responses/422',
            ),
        ]
    )]
    public function create_database_postgresql(Request $request)
    {
        return $this->create_database($request, NewDatabaseTypes::POSTGRESQL);
    }

    #[OA\Post(
        summary: 'Create (Clickhouse)',
        description: 'Create a new Clickhouse database.',
        path: '/databases/clickhouse',
        operationId: 'create-database-clickhouse',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Databases'],

        requestBody: new OA\RequestBody(
            description: 'Database data',
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    type: 'object',
                    required: ['server_uuid', 'project_uuid', 'environment_name', 'environment_uuid'],
                    properties: [
                        'server_uuid' => ['type' => 'string', 'description' => 'UUID of the server'],
                        'project_uuid' => ['type' => 'string', 'description' => 'UUID of the project'],
                        'environment_name' => ['type' => 'string', 'description' => 'Name of the environment. You need to provide at least one of environment_name or environment_uuid.'],
                        'environment_uuid' => ['type' => 'string', 'description' => 'UUID of the environment. You need to provide at least one of environment_name or environment_uuid.'],
                        'destination_uuid' => ['type' => 'string',  'description' => 'UUID of the destination if the server has multiple destinations'],
                        'clickhouse_admin_user' => ['type' => 'string', 'description' => 'Clickhouse admin user'],
                        'clickhouse_admin_password' => ['type' => 'string', 'description' => 'Clickhouse admin password'],
                        'name' => ['type' => 'string', 'description' => 'Name of the database'],
                        'description' => ['type' => 'string', 'description' => 'Description of the database'],
                        'image' => ['type' => 'string', 'description' => 'Docker Image of the database'],
                        'is_public' => ['type' => 'boolean', 'description' => 'Is the database public?'],
                        'public_port' => ['type' => 'integer', 'description' => 'Public port of the database'],
                        'limits_memory' => ['type' => 'string', 'description' => 'Memory limit of the database'],
                        'limits_memory_swap' => ['type' => 'string', 'description' => 'Memory swap limit of the database'],
                        'limits_memory_swappiness' => ['type' => 'integer', 'description' => 'Memory swappiness of the database'],
                        'limits_memory_reservation' => ['type' => 'string', 'description' => 'Memory reservation of the database'],
                        'limits_cpus' => ['type' => 'string', 'description' => 'CPU limit of the database'],
                        'limits_cpuset' => ['type' => 'string', 'description' => 'CPU set of the database'],
                        'limits_cpu_shares' => ['type' => 'integer', 'description' => 'CPU shares of the database'],
                        'instant_deploy' => ['type' => 'boolean', 'description' => 'Instant deploy the database'],
                    ],
                ),
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Database updated',
            ),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 400,
                ref: '#/components/responses/400',
            ),
            new OA\Response(
                response: 422,
                ref: '#/components/responses/422',
            ),
        ]
    )]
    public function create_database_clickhouse(Request $request)
    {
        return $this->create_database($request, NewDatabaseTypes::CLICKHOUSE);
    }

    #[OA\Post(
        summary: 'Create (DragonFly)',
        description: 'Create a new DragonFly database.',
        path: '/databases/dragonfly',
        operationId: 'create-database-dragonfly',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Databases'],

        requestBody: new OA\RequestBody(
            description: 'Database data',
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    type: 'object',
                    required: ['server_uuid', 'project_uuid', 'environment_name', 'environment_uuid'],
                    properties: [
                        'server_uuid' => ['type' => 'string', 'description' => 'UUID of the server'],
                        'project_uuid' => ['type' => 'string', 'description' => 'UUID of the project'],
                        'environment_name' => ['type' => 'string', 'description' => 'Name of the environment. You need to provide at least one of environment_name or environment_uuid.'],
                        'environment_uuid' => ['type' => 'string', 'description' => 'UUID of the environment. You need to provide at least one of environment_name or environment_uuid.'],
                        'destination_uuid' => ['type' => 'string', 'description' => 'UUID of the destination if the server has multiple destinations'],
                        'dragonfly_password' => ['type' => 'string', 'description' => 'DragonFly password'],
                        'name' => ['type' => 'string', 'description' => 'Name of the database'],
                        'description' => ['type' => 'string', 'description' => 'Description of the database'],
                        'image' => ['type' => 'string', 'description' => 'Docker Image of the database'],
                        'is_public' => ['type' => 'boolean', 'description' => 'Is the database public?'],
                        'public_port' => ['type' => 'integer', 'description' => 'Public port of the database'],
                        'limits_memory' => ['type' => 'string', 'description' => 'Memory limit of the database'],
                        'limits_memory_swap' => ['type' => 'string', 'description' => 'Memory swap limit of the database'],
                        'limits_memory_swappiness' => ['type' => 'integer', 'description' => 'Memory swappiness of the database'],
                        'limits_memory_reservation' => ['type' => 'string', 'description' => 'Memory reservation of the database'],
                        'limits_cpus' => ['type' => 'string', 'description' => 'CPU limit of the database'],
                        'limits_cpuset' => ['type' => 'string', 'description' => 'CPU set of the database'],
                        'limits_cpu_shares' => ['type' => 'integer', 'description' => 'CPU shares of the database'],
                        'instant_deploy' => ['type' => 'boolean', 'description' => 'Instant deploy the database'],
                    ],
                ),
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Database updated',
            ),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 400,
                ref: '#/components/responses/400',
            ),
            new OA\Response(
                response: 422,
                ref: '#/components/responses/422',
            ),
        ]
    )]
    public function create_database_dragonfly(Request $request)
    {
        return $this->create_database($request, NewDatabaseTypes::DRAGONFLY);
    }

    #[OA\Post(
        summary: 'Create (Redis)',
        description: 'Create a new Redis database.',
        path: '/databases/redis',
        operationId: 'create-database-redis',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Databases'],

        requestBody: new OA\RequestBody(
            description: 'Database data',
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    type: 'object',
                    required: ['server_uuid', 'project_uuid', 'environment_name', 'environment_uuid'],
                    properties: [
                        'server_uuid' => ['type' => 'string', 'description' => 'UUID of the server'],
                        'project_uuid' => ['type' => 'string', 'description' => 'UUID of the project'],
                        'environment_name' => ['type' => 'string', 'description' => 'Name of the environment. You need to provide at least one of environment_name or environment_uuid.'],
                        'environment_uuid' => ['type' => 'string', 'description' => 'UUID of the environment. You need to provide at least one of environment_name or environment_uuid.'],
                        'destination_uuid' => ['type' => 'string', 'description' => 'UUID of the destination if the server has multiple destinations'],
                        'redis_password' => ['type' => 'string', 'description' => 'Redis password'],
                        'redis_conf' => ['type' => 'string', 'description' => 'Redis conf'],
                        'name' => ['type' => 'string', 'description' => 'Name of the database'],
                        'description' => ['type' => 'string', 'description' => 'Description of the database'],
                        'image' => ['type' => 'string', 'description' => 'Docker Image of the database'],
                        'is_public' => ['type' => 'boolean', 'description' => 'Is the database public?'],
                        'public_port' => ['type' => 'integer', 'description' => 'Public port of the database'],
                        'limits_memory' => ['type' => 'string', 'description' => 'Memory limit of the database'],
                        'limits_memory_swap' => ['type' => 'string', 'description' => 'Memory swap limit of the database'],
                        'limits_memory_swappiness' => ['type' => 'integer', 'description' => 'Memory swappiness of the database'],
                        'limits_memory_reservation' => ['type' => 'string', 'description' => 'Memory reservation of the database'],
                        'limits_cpus' => ['type' => 'string', 'description' => 'CPU limit of the database'],
                        'limits_cpuset' => ['type' => 'string', 'description' => 'CPU set of the database'],
                        'limits_cpu_shares' => ['type' => 'integer', 'description' => 'CPU shares of the database'],
                        'instant_deploy' => ['type' => 'boolean', 'description' => 'Instant deploy the database'],
                    ],
                ),
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Database updated',
            ),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 400,
                ref: '#/components/responses/400',
            ),
            new OA\Response(
                response: 422,
                ref: '#/components/responses/422',
            ),
        ]
    )]
    public function create_database_redis(Request $request)
    {
        return $this->create_database($request, NewDatabaseTypes::REDIS);
    }

    #[OA\Post(
        summary: 'Create (KeyDB)',
        description: 'Create a new KeyDB database.',
        path: '/databases/keydb',
        operationId: 'create-database-keydb',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Databases'],

        requestBody: new OA\RequestBody(
            description: 'Database data',
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    type: 'object',
                    required: ['server_uuid', 'project_uuid', 'environment_name', 'environment_uuid'],
                    properties: [
                        'server_uuid' => ['type' => 'string', 'description' => 'UUID of the server'],
                        'project_uuid' => ['type' => 'string', 'description' => 'UUID of the project'],
                        'environment_name' => ['type' => 'string', 'description' => 'Name of the environment. You need to provide at least one of environment_name or environment_uuid.'],
                        'environment_uuid' => ['type' => 'string', 'description' => 'UUID of the environment. You need to provide at least one of environment_name or environment_uuid.'],
                        'destination_uuid' => ['type' => 'string', 'description' => 'UUID of the destination if the server has multiple destinations'],
                        'keydb_password' => ['type' => 'string', 'description' => 'KeyDB password'],
                        'keydb_conf' => ['type' => 'string', 'description' => 'KeyDB conf'],
                        'name' => ['type' => 'string', 'description' => 'Name of the database'],
                        'description' => ['type' => 'string', 'description' => 'Description of the database'],
                        'image' => ['type' => 'string', 'description' => 'Docker Image of the database'],
                        'is_public' => ['type' => 'boolean', 'description' => 'Is the database public?'],
                        'public_port' => ['type' => 'integer', 'description' => 'Public port of the database'],
                        'limits_memory' => ['type' => 'string', 'description' => 'Memory limit of the database'],
                        'limits_memory_swap' => ['type' => 'string', 'description' => 'Memory swap limit of the database'],
                        'limits_memory_swappiness' => ['type' => 'integer', 'description' => 'Memory swappiness of the database'],
                        'limits_memory_reservation' => ['type' => 'string', 'description' => 'Memory reservation of the database'],
                        'limits_cpus' => ['type' => 'string', 'description' => 'CPU limit of the database'],
                        'limits_cpuset' => ['type' => 'string', 'description' => 'CPU set of the database'],
                        'limits_cpu_shares' => ['type' => 'integer', 'description' => 'CPU shares of the database'],
                        'instant_deploy' => ['type' => 'boolean', 'description' => 'Instant deploy the database'],
                    ],
                ),
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Database updated',
            ),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 400,
                ref: '#/components/responses/400',
            ),
            new OA\Response(
                response: 422,
                ref: '#/components/responses/422',
            ),
        ]
    )]
    public function create_database_keydb(Request $request)
    {
        return $this->create_database($request, NewDatabaseTypes::KEYDB);
    }

    #[OA\Post(
        summary: 'Create (MariaDB)',
        description: 'Create a new MariaDB database.',
        path: '/databases/mariadb',
        operationId: 'create-database-mariadb',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Databases'],

        requestBody: new OA\RequestBody(
            description: 'Database data',
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    type: 'object',
                    required: ['server_uuid', 'project_uuid', 'environment_name', 'environment_uuid'],
                    properties: [
                        'server_uuid' => ['type' => 'string', 'description' => 'UUID of the server'],
                        'project_uuid' => ['type' => 'string', 'description' => 'UUID of the project'],
                        'environment_name' => ['type' => 'string', 'description' => 'Name of the environment. You need to provide at least one of environment_name or environment_uuid.'],
                        'environment_uuid' => ['type' => 'string', 'description' => 'UUID of the environment. You need to provide at least one of environment_name or environment_uuid.'],
                        'destination_uuid' => ['type' => 'string', 'description' => 'UUID of the destination if the server has multiple destinations'],
                        'mariadb_conf' => ['type' => 'string', 'description' => 'MariaDB conf'],
                        'mariadb_root_password' => ['type' => 'string', 'description' => 'MariaDB root password'],
                        'mariadb_user' => ['type' => 'string', 'description' => 'MariaDB user'],
                        'mariadb_password' => ['type' => 'string', 'description' => 'MariaDB password'],
                        'mariadb_database' => ['type' => 'string', 'description' => 'MariaDB database'],
                        'name' => ['type' => 'string', 'description' => 'Name of the database'],
                        'description' => ['type' => 'string', 'description' => 'Description of the database'],
                        'image' => ['type' => 'string', 'description' => 'Docker Image of the database'],
                        'is_public' => ['type' => 'boolean', 'description' => 'Is the database public?'],
                        'public_port' => ['type' => 'integer', 'description' => 'Public port of the database'],
                        'limits_memory' => ['type' => 'string', 'description' => 'Memory limit of the database'],
                        'limits_memory_swap' => ['type' => 'string', 'description' => 'Memory swap limit of the database'],
                        'limits_memory_swappiness' => ['type' => 'integer', 'description' => 'Memory swappiness of the database'],
                        'limits_memory_reservation' => ['type' => 'string', 'description' => 'Memory reservation of the database'],
                        'limits_cpus' => ['type' => 'string', 'description' => 'CPU limit of the database'],
                        'limits_cpuset' => ['type' => 'string', 'description' => 'CPU set of the database'],
                        'limits_cpu_shares' => ['type' => 'integer', 'description' => 'CPU shares of the database'],
                        'instant_deploy' => ['type' => 'boolean', 'description' => 'Instant deploy the database'],
                    ],
                ),
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Database updated',
            ),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 400,
                ref: '#/components/responses/400',
            ),
            new OA\Response(
                response: 422,
                ref: '#/components/responses/422',
            ),
        ]
    )]
    public function create_database_mariadb(Request $request)
    {
        return $this->create_database($request, NewDatabaseTypes::MARIADB);
    }

    #[OA\Post(
        summary: 'Create (MySQL)',
        description: 'Create a new MySQL database.',
        path: '/databases/mysql',
        operationId: 'create-database-mysql',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Databases'],

        requestBody: new OA\RequestBody(
            description: 'Database data',
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    type: 'object',
                    required: ['server_uuid', 'project_uuid', 'environment_name', 'environment_uuid'],
                    properties: [
                        'server_uuid' => ['type' => 'string', 'description' => 'UUID of the server'],
                        'project_uuid' => ['type' => 'string', 'description' => 'UUID of the project'],
                        'environment_name' => ['type' => 'string', 'description' => 'Name of the environment. You need to provide at least one of environment_name or environment_uuid.'],
                        'environment_uuid' => ['type' => 'string', 'description' => 'UUID of the environment. You need to provide at least one of environment_name or environment_uuid.'],
                        'destination_uuid' => ['type' => 'string', 'description' => 'UUID of the destination if the server has multiple destinations'],
                        'mysql_root_password' => ['type' => 'string', 'description' => 'MySQL root password'],
                        'mysql_password' => ['type' => 'string', 'description' => 'MySQL password'],
                        'mysql_user' => ['type' => 'string', 'description' => 'MySQL user'],
                        'mysql_database' => ['type' => 'string', 'description' => 'MySQL database'],
                        'mysql_conf' => ['type' => 'string', 'description' => 'MySQL conf'],
                        'name' => ['type' => 'string', 'description' => 'Name of the database'],
                        'description' => ['type' => 'string', 'description' => 'Description of the database'],
                        'image' => ['type' => 'string', 'description' => 'Docker Image of the database'],
                        'is_public' => ['type' => 'boolean', 'description' => 'Is the database public?'],
                        'public_port' => ['type' => 'integer', 'description' => 'Public port of the database'],
                        'limits_memory' => ['type' => 'string', 'description' => 'Memory limit of the database'],
                        'limits_memory_swap' => ['type' => 'string', 'description' => 'Memory swap limit of the database'],
                        'limits_memory_swappiness' => ['type' => 'integer', 'description' => 'Memory swappiness of the database'],
                        'limits_memory_reservation' => ['type' => 'string', 'description' => 'Memory reservation of the database'],
                        'limits_cpus' => ['type' => 'string', 'description' => 'CPU limit of the database'],
                        'limits_cpuset' => ['type' => 'string', 'description' => 'CPU set of the database'],
                        'limits_cpu_shares' => ['type' => 'integer', 'description' => 'CPU shares of the database'],
                        'instant_deploy' => ['type' => 'boolean', 'description' => 'Instant deploy the database'],
                    ],
                ),
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Database updated',
            ),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 400,
                ref: '#/components/responses/400',
            ),
            new OA\Response(
                response: 422,
                ref: '#/components/responses/422',
            ),
        ]
    )]
    public function create_database_mysql(Request $request)
    {
        return $this->create_database($request, NewDatabaseTypes::MYSQL);
    }

    #[OA\Post(
        summary: 'Create (MongoDB)',
        description: 'Create a new MongoDB database.',
        path: '/databases/mongodb',
        operationId: 'create-database-mongodb',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Databases'],

        requestBody: new OA\RequestBody(
            description: 'Database data',
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    type: 'object',
                    required: ['server_uuid', 'project_uuid', 'environment_name', 'environment_uuid'],
                    properties: [
                        'server_uuid' => ['type' => 'string', 'description' => 'UUID of the server'],
                        'project_uuid' => ['type' => 'string', 'description' => 'UUID of the project'],
                        'environment_name' => ['type' => 'string', 'description' => 'Name of the environment. You need to provide at least one of environment_name or environment_uuid.'],
                        'environment_uuid' => ['type' => 'string', 'description' => 'UUID of the environment. You need to provide at least one of environment_name or environment_uuid.'],
                        'destination_uuid' => ['type' => 'string', 'description' => 'UUID of the destination if the server has multiple destinations'],
                        'mongo_conf' => ['type' => 'string', 'description' => 'MongoDB conf'],
                        'mongo_initdb_root_username' => ['type' => 'string', 'description' => 'MongoDB initdb root username'],
                        'name' => ['type' => 'string', 'description' => 'Name of the database'],
                        'description' => ['type' => 'string', 'description' => 'Description of the database'],
                        'image' => ['type' => 'string', 'description' => 'Docker Image of the database'],
                        'is_public' => ['type' => 'boolean', 'description' => 'Is the database public?'],
                        'public_port' => ['type' => 'integer', 'description' => 'Public port of the database'],
                        'limits_memory' => ['type' => 'string', 'description' => 'Memory limit of the database'],
                        'limits_memory_swap' => ['type' => 'string', 'description' => 'Memory swap limit of the database'],
                        'limits_memory_swappiness' => ['type' => 'integer', 'description' => 'Memory swappiness of the database'],
                        'limits_memory_reservation' => ['type' => 'string', 'description' => 'Memory reservation of the database'],
                        'limits_cpus' => ['type' => 'string', 'description' => 'CPU limit of the database'],
                        'limits_cpuset' => ['type' => 'string', 'description' => 'CPU set of the database'],
                        'limits_cpu_shares' => ['type' => 'integer', 'description' => 'CPU shares of the database'],
                        'instant_deploy' => ['type' => 'boolean', 'description' => 'Instant deploy the database'],
                    ],
                ),
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Database updated',
            ),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 400,
                ref: '#/components/responses/400',
            ),
            new OA\Response(
                response: 422,
                ref: '#/components/responses/422',
            ),
        ]
    )]
    public function create_database_mongodb(Request $request)
    {
        return $this->create_database($request, NewDatabaseTypes::MONGODB);
    }

    public function create_database(Request $request, NewDatabaseTypes $type)
    {
        $allowedFields = ['name', 'description', 'image', 'public_port', 'is_public', 'project_uuid', 'environment_name', 'environment_uuid', 'server_uuid', 'destination_uuid', 'instant_deploy', 'limits_memory', 'limits_memory_swap', 'limits_memory_swappiness', 'limits_memory_reservation', 'limits_cpus', 'limits_cpuset', 'limits_cpu_shares', 'postgres_user', 'postgres_password', 'postgres_db', 'postgres_initdb_args', 'postgres_host_auth_method', 'postgres_conf', 'clickhouse_admin_user', 'clickhouse_admin_password', 'dragonfly_password', 'redis_password', 'redis_conf', 'keydb_password', 'keydb_conf', 'mariadb_conf', 'mariadb_root_password', 'mariadb_user', 'mariadb_password', 'mariadb_database', 'mongo_conf', 'mongo_initdb_root_username', 'mongo_initdb_root_password', 'mongo_initdb_database', 'mysql_root_password', 'mysql_password', 'mysql_user', 'mysql_database', 'mysql_conf'];

        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        // Use a generic authorization for database creation - using PostgreSQL as representative model
        $this->authorize('create', StandalonePostgresql::class);

        $return = validateIncomingRequest($request);
        if ($return instanceof \Illuminate\Http\JsonResponse) {
            return $return;
        }

        $extraFields = array_diff(array_keys($request->all()), $allowedFields);
        if (! empty($extraFields)) {
            $errors = collect([]);
            if (! empty($extraFields)) {
                foreach ($extraFields as $field) {
                    $errors->add($field, 'This field is not allowed.');
                }
            }

            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $errors,
            ], 422);
        }
        $environmentUuid = $request->environment_uuid;
        $environmentName = $request->environment_name;
        if (blank($environmentUuid) && blank($environmentName)) {
            return response()->json(['message' => 'You need to provide at least one of environment_name or environment_uuid.'], 422);
        }
        $serverUuid = $request->server_uuid;
        $instantDeploy = $request->instant_deploy ?? false;
        if ($request->is_public && ! $request->public_port) {
            $request->offsetSet('is_public', false);
        }
        $project = Project::whereTeamId($teamId)->whereUuid($request->project_uuid)->first();
        if (! $project) {
            return response()->json(['message' => 'Project not found.'], 404);
        }
        $environment = $project->environments()->where('name', $environmentName)->first();
        if (! $environment) {
            $environment = $project->environments()->where('uuid', $environmentUuid)->first();
        }
        if (! $environment) {
            return response()->json(['message' => 'You need to provide a valid environment_name or environment_uuid.'], 422);
        }
        $server = Server::whereTeamId($teamId)->whereUuid($serverUuid)->first();
        if (! $server) {
            return response()->json(['message' => 'Server not found.'], 404);
        }
        $destinations = $server->destinations();
        if ($destinations->count() == 0) {
            return response()->json(['message' => 'Server has no destinations.'], 400);
        }
        if ($destinations->count() > 1 && ! $request->has('destination_uuid')) {
            return response()->json(['message' => 'Server has multiple destinations and you do not set destination_uuid.'], 400);
        }
        $destination = $destinations->first();
        if ($destinations->count() > 1 && $request->has('destination_uuid')) {
            $destination = $destinations->where('uuid', $request->destination_uuid)->first();
            if (! $destination) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => [
                        'destination_uuid' => 'Provided destination_uuid does not belong to the specified server.',
                    ],
                ], 422);
            }
        }

        if ($request->has('public_port') && $request->is_public) {
            if (isPublicPortAlreadyUsed($server, $request->public_port)) {
                return response()->json(['message' => 'Public port already used by another database.'], 400);
            }
        }
        $validator = customApiValidator($request->all(), [
            'name' => 'string|max:255',
            'description' => 'string|nullable',
            'image' => 'string',
            'project_uuid' => 'string|required',
            'environment_name' => 'string|nullable',
            'environment_uuid' => 'string|nullable',
            'server_uuid' => 'string|required',
            'destination_uuid' => 'string',
            'is_public' => 'boolean',
            'public_port' => 'numeric|nullable',
            'limits_memory' => 'string',
            'limits_memory_swap' => 'string',
            'limits_memory_swappiness' => 'numeric',
            'limits_memory_reservation' => 'string',
            'limits_cpus' => 'string',
            'limits_cpuset' => 'string|nullable',
            'limits_cpu_shares' => 'numeric',
            'instant_deploy' => 'boolean',
        ]);
        if ($validator->failed()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }
        if ($request->public_port) {
            if ($request->public_port < 1024 || $request->public_port > 65535) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => [
                        'public_port' => 'The public port should be between 1024 and 65535.',
                    ],
                ], 422);
            }
        }
        if ($type === NewDatabaseTypes::POSTGRESQL) {
            $allowedFields = ['name', 'description', 'image', 'public_port', 'is_public', 'project_uuid', 'environment_name', 'environment_uuid', 'server_uuid', 'destination_uuid', 'instant_deploy', 'limits_memory', 'limits_memory_swap', 'limits_memory_swappiness', 'limits_memory_reservation', 'limits_cpus', 'limits_cpuset', 'limits_cpu_shares', 'postgres_user', 'postgres_password', 'postgres_db', 'postgres_initdb_args', 'postgres_host_auth_method', 'postgres_conf'];
            $validator = customApiValidator($request->all(), [
                'postgres_user' => 'string',
                'postgres_password' => 'string',
                'postgres_db' => 'string',
                'postgres_initdb_args' => 'string',
                'postgres_host_auth_method' => 'string',
                'postgres_conf' => 'string',
            ]);
            $extraFields = array_diff(array_keys($request->all()), $allowedFields);
            if ($validator->fails() || ! empty($extraFields)) {
                $errors = $validator->errors();
                if (! empty($extraFields)) {
                    foreach ($extraFields as $field) {
                        $errors->add($field, 'This field is not allowed.');
                    }
                }

                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => $errors,
                ], 422);
            }
            removeUnnecessaryFieldsFromRequest($request);
            if ($request->has('postgres_conf')) {
                if (! isBase64Encoded($request->postgres_conf)) {
                    return response()->json([
                        'message' => 'Validation failed.',
                        'errors' => [
                            'postgres_conf' => 'The postgres_conf should be base64 encoded.',
                        ],
                    ], 422);
                }
                $postgresConf = base64_decode($request->postgres_conf);
                if (mb_detect_encoding($postgresConf, 'UTF-8', true) === false) {
                    return response()->json([
                        'message' => 'Validation failed.',
                        'errors' => [
                            'postgres_conf' => 'The postgres_conf should be base64 encoded.',
                        ],
                    ], 422);
                }
                $request->offsetSet('postgres_conf', $postgresConf);
            }
            $database = create_standalone_postgresql($environment->id, $destination->uuid, $request->all());
            if ($instantDeploy) {
                StartDatabase::dispatch($database);
            }
            $database->refresh();
            $payload = [
                'uuid' => $database->uuid,
                'internal_db_url' => $database->internal_db_url,
            ];
            if ($database->is_public && $database->public_port) {
                $payload['external_db_url'] = $database->external_db_url;
            }

            return response()->json(serializeApiResponse($payload))->setStatusCode(201);
        } elseif ($type === NewDatabaseTypes::MARIADB) {
            $allowedFields = ['name', 'description', 'image', 'public_port', 'is_public', 'project_uuid', 'environment_name', 'environment_uuid', 'server_uuid', 'destination_uuid', 'instant_deploy', 'limits_memory', 'limits_memory_swap', 'limits_memory_swappiness', 'limits_memory_reservation', 'limits_cpus', 'limits_cpuset', 'limits_cpu_shares', 'mariadb_conf', 'mariadb_root_password', 'mariadb_user', 'mariadb_password', 'mariadb_database'];
            $validator = customApiValidator($request->all(), [
                'clickhouse_admin_user' => 'string',
                'clickhouse_admin_password' => 'string',
            ]);
            $extraFields = array_diff(array_keys($request->all()), $allowedFields);
            if ($validator->fails() || ! empty($extraFields)) {
                $errors = $validator->errors();
                if (! empty($extraFields)) {
                    foreach ($extraFields as $field) {
                        $errors->add($field, 'This field is not allowed.');
                    }
                }

                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => $errors,
                ], 422);
            }
            removeUnnecessaryFieldsFromRequest($request);
            if ($request->has('mariadb_conf')) {
                if (! isBase64Encoded($request->mariadb_conf)) {
                    return response()->json([
                        'message' => 'Validation failed.',
                        'errors' => [
                            'mariadb_conf' => 'The mariadb_conf should be base64 encoded.',
                        ],
                    ], 422);
                }
                $mariadbConf = base64_decode($request->mariadb_conf);
                if (mb_detect_encoding($mariadbConf, 'UTF-8', true) === false) {
                    return response()->json([
                        'message' => 'Validation failed.',
                        'errors' => [
                            'mariadb_conf' => 'The mariadb_conf should be base64 encoded.',
                        ],
                    ], 422);
                }
                $request->offsetSet('mariadb_conf', $mariadbConf);
            }
            $database = create_standalone_mariadb($environment->id, $destination->uuid, $request->all());
            if ($instantDeploy) {
                StartDatabase::dispatch($database);
            }

            $database->refresh();
            $payload = [
                'uuid' => $database->uuid,
                'internal_db_url' => $database->internal_db_url,
            ];
            if ($database->is_public && $database->public_port) {
                $payload['external_db_url'] = $database->external_db_url;
            }

            return response()->json(serializeApiResponse($payload))->setStatusCode(201);
        } elseif ($type === NewDatabaseTypes::MYSQL) {
            $allowedFields = ['name', 'description', 'image', 'public_port', 'is_public', 'project_uuid', 'environment_name', 'environment_uuid', 'server_uuid', 'destination_uuid', 'instant_deploy', 'limits_memory', 'limits_memory_swap', 'limits_memory_swappiness', 'limits_memory_reservation', 'limits_cpus', 'limits_cpuset', 'limits_cpu_shares', 'mysql_root_password', 'mysql_password', 'mysql_user', 'mysql_database', 'mysql_conf'];
            $validator = customApiValidator($request->all(), [
                'mysql_root_password' => 'string',
                'mysql_password' => 'string',
                'mysql_user' => 'string',
                'mysql_database' => 'string',
                'mysql_conf' => 'string',
            ]);
            $extraFields = array_diff(array_keys($request->all()), $allowedFields);
            if ($validator->fails() || ! empty($extraFields)) {
                $errors = $validator->errors();
                if (! empty($extraFields)) {
                    foreach ($extraFields as $field) {
                        $errors->add($field, 'This field is not allowed.');
                    }
                }

                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => $errors,
                ], 422);
            }
            removeUnnecessaryFieldsFromRequest($request);
            if ($request->has('mysql_conf')) {
                if (! isBase64Encoded($request->mysql_conf)) {
                    return response()->json([
                        'message' => 'Validation failed.',
                        'errors' => [
                            'mysql_conf' => 'The mysql_conf should be base64 encoded.',
                        ],
                    ], 422);
                }
                $mysqlConf = base64_decode($request->mysql_conf);
                if (mb_detect_encoding($mysqlConf, 'UTF-8', true) === false) {
                    return response()->json([
                        'message' => 'Validation failed.',
                        'errors' => [
                            'mysql_conf' => 'The mysql_conf should be base64 encoded.',
                        ],
                    ], 422);
                }
                $request->offsetSet('mysql_conf', $mysqlConf);
            }
            $database = create_standalone_mysql($environment->id, $destination->uuid, $request->all());
            if ($instantDeploy) {
                StartDatabase::dispatch($database);
            }

            $database->refresh();
            $payload = [
                'uuid' => $database->uuid,
                'internal_db_url' => $database->internal_db_url,
            ];
            if ($database->is_public && $database->public_port) {
                $payload['external_db_url'] = $database->external_db_url;
            }

            return response()->json(serializeApiResponse($payload))->setStatusCode(201);
        } elseif ($type === NewDatabaseTypes::REDIS) {
            $allowedFields = ['name', 'description', 'image', 'public_port', 'is_public', 'project_uuid', 'environment_name', 'environment_uuid', 'server_uuid', 'destination_uuid', 'instant_deploy', 'limits_memory', 'limits_memory_swap', 'limits_memory_swappiness', 'limits_memory_reservation', 'limits_cpus', 'limits_cpuset', 'limits_cpu_shares', 'redis_password', 'redis_conf'];
            $validator = customApiValidator($request->all(), [
                'redis_password' => 'string',
                'redis_conf' => 'string',
            ]);
            $extraFields = array_diff(array_keys($request->all()), $allowedFields);
            if ($validator->fails() || ! empty($extraFields)) {
                $errors = $validator->errors();
                if (! empty($extraFields)) {
                    foreach ($extraFields as $field) {
                        $errors->add($field, 'This field is not allowed.');
                    }
                }

                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => $errors,
                ], 422);
            }
            removeUnnecessaryFieldsFromRequest($request);
            if ($request->has('redis_conf')) {
                if (! isBase64Encoded($request->redis_conf)) {
                    return response()->json([
                        'message' => 'Validation failed.',
                        'errors' => [
                            'redis_conf' => 'The redis_conf should be base64 encoded.',
                        ],
                    ], 422);
                }
                $redisConf = base64_decode($request->redis_conf);
                if (mb_detect_encoding($redisConf, 'UTF-8', true) === false) {
                    return response()->json([
                        'message' => 'Validation failed.',
                        'errors' => [
                            'redis_conf' => 'The redis_conf should be base64 encoded.',
                        ],
                    ], 422);
                }
                $request->offsetSet('redis_conf', $redisConf);
            }
            $database = create_standalone_redis($environment->id, $destination->uuid, $request->all());
            if ($instantDeploy) {
                StartDatabase::dispatch($database);
            }

            $database->refresh();
            $payload = [
                'uuid' => $database->uuid,
                'internal_db_url' => $database->internal_db_url,
            ];
            if ($database->is_public && $database->public_port) {
                $payload['external_db_url'] = $database->external_db_url;
            }

            return response()->json(serializeApiResponse($payload))->setStatusCode(201);
        } elseif ($type === NewDatabaseTypes::DRAGONFLY) {
            $allowedFields = ['name', 'description', 'image', 'public_port', 'is_public', 'project_uuid', 'environment_name', 'environment_uuid', 'server_uuid', 'destination_uuid', 'instant_deploy', 'limits_memory', 'limits_memory_swap', 'limits_memory_swappiness', 'limits_memory_reservation', 'limits_cpus', 'limits_cpuset', 'limits_cpu_shares',  'dragonfly_password'];
            $validator = customApiValidator($request->all(), [
                'dragonfly_password' => 'string',
            ]);

            $extraFields = array_diff(array_keys($request->all()), $allowedFields);
            if ($validator->fails() || ! empty($extraFields)) {
                $errors = $validator->errors();
                if (! empty($extraFields)) {
                    foreach ($extraFields as $field) {
                        $errors->add($field, 'This field is not allowed.');
                    }
                }

                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => $errors,
                ], 422);
            }

            removeUnnecessaryFieldsFromRequest($request);
            $database = create_standalone_dragonfly($environment->id, $destination->uuid, $request->all());
            if ($instantDeploy) {
                StartDatabase::dispatch($database);
            }

            return response()->json(serializeApiResponse([
                'uuid' => $database->uuid,
            ]))->setStatusCode(201);
        } elseif ($type === NewDatabaseTypes::KEYDB) {
            $allowedFields = ['name', 'description', 'image', 'public_port', 'is_public', 'project_uuid', 'environment_name', 'environment_uuid', 'server_uuid', 'destination_uuid', 'instant_deploy', 'limits_memory', 'limits_memory_swap', 'limits_memory_swappiness', 'limits_memory_reservation', 'limits_cpus', 'limits_cpuset', 'limits_cpu_shares', 'keydb_password', 'keydb_conf'];
            $validator = customApiValidator($request->all(), [
                'keydb_password' => 'string',
                'keydb_conf' => 'string',
            ]);
            $extraFields = array_diff(array_keys($request->all()), $allowedFields);
            if ($validator->fails() || ! empty($extraFields)) {
                $errors = $validator->errors();
                if (! empty($extraFields)) {
                    foreach ($extraFields as $field) {
                        $errors->add($field, 'This field is not allowed.');
                    }
                }

                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => $errors,
                ], 422);
            }
            removeUnnecessaryFieldsFromRequest($request);
            if ($request->has('keydb_conf')) {
                if (! isBase64Encoded($request->keydb_conf)) {
                    return response()->json([
                        'message' => 'Validation failed.',
                        'errors' => [
                            'keydb_conf' => 'The keydb_conf should be base64 encoded.',
                        ],
                    ], 422);
                }
                $keydbConf = base64_decode($request->keydb_conf);
                if (mb_detect_encoding($keydbConf, 'UTF-8', true) === false) {
                    return response()->json([
                        'message' => 'Validation failed.',
                        'errors' => [
                            'keydb_conf' => 'The keydb_conf should be base64 encoded.',
                        ],
                    ], 422);
                }
                $request->offsetSet('keydb_conf', $keydbConf);
            }
            $database = create_standalone_keydb($environment->id, $destination->uuid, $request->all());
            if ($instantDeploy) {
                StartDatabase::dispatch($database);
            }

            $database->refresh();
            $payload = [
                'uuid' => $database->uuid,
                'internal_db_url' => $database->internal_db_url,
            ];
            if ($database->is_public && $database->public_port) {
                $payload['external_db_url'] = $database->external_db_url;
            }

            return response()->json(serializeApiResponse($payload))->setStatusCode(201);
        } elseif ($type === NewDatabaseTypes::CLICKHOUSE) {
            $allowedFields = ['name', 'description', 'image', 'public_port', 'is_public', 'project_uuid', 'environment_name', 'environment_uuid', 'server_uuid', 'destination_uuid', 'instant_deploy', 'limits_memory', 'limits_memory_swap', 'limits_memory_swappiness', 'limits_memory_reservation', 'limits_cpus', 'limits_cpuset', 'limits_cpu_shares',  'clickhouse_admin_user', 'clickhouse_admin_password'];
            $validator = customApiValidator($request->all(), [
                'clickhouse_admin_user' => 'string',
                'clickhouse_admin_password' => 'string',
            ]);
            $extraFields = array_diff(array_keys($request->all()), $allowedFields);
            if ($validator->fails() || ! empty($extraFields)) {
                $errors = $validator->errors();
                if (! empty($extraFields)) {
                    foreach ($extraFields as $field) {
                        $errors->add($field, 'This field is not allowed.');
                    }
                }

                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => $errors,
                ], 422);
            }
            removeUnnecessaryFieldsFromRequest($request);
            $database = create_standalone_clickhouse($environment->id, $destination->uuid, $request->all());
            if ($instantDeploy) {
                StartDatabase::dispatch($database);
            }

            $database->refresh();
            $payload = [
                'uuid' => $database->uuid,
                'internal_db_url' => $database->internal_db_url,
            ];
            if ($database->is_public && $database->public_port) {
                $payload['external_db_url'] = $database->external_db_url;
            }

            return response()->json(serializeApiResponse($payload))->setStatusCode(201);
        } elseif ($type === NewDatabaseTypes::MONGODB) {
            $allowedFields = ['name', 'description', 'image', 'public_port', 'is_public', 'project_uuid', 'environment_name', 'environment_uuid', 'server_uuid', 'destination_uuid', 'instant_deploy', 'limits_memory', 'limits_memory_swap', 'limits_memory_swappiness', 'limits_memory_reservation', 'limits_cpus', 'limits_cpuset', 'limits_cpu_shares', 'mongo_conf', 'mongo_initdb_root_username', 'mongo_initdb_root_password', 'mongo_initdb_database'];
            $validator = customApiValidator($request->all(), [
                'mongo_conf' => 'string',
                'mongo_initdb_root_username' => 'string',
                'mongo_initdb_root_password' => 'string',
                'mongo_initdb_database' => 'string',
            ]);
            $extraFields = array_diff(array_keys($request->all()), $allowedFields);
            if ($validator->fails() || ! empty($extraFields)) {
                $errors = $validator->errors();
                if (! empty($extraFields)) {
                    foreach ($extraFields as $field) {
                        $errors->add($field, 'This field is not allowed.');
                    }
                }

                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => $errors,
                ], 422);
            }
            removeUnnecessaryFieldsFromRequest($request);
            if ($request->has('mongo_conf')) {
                if (! isBase64Encoded($request->mongo_conf)) {
                    return response()->json([
                        'message' => 'Validation failed.',
                        'errors' => [
                            'mongo_conf' => 'The mongo_conf should be base64 encoded.',
                        ],
                    ], 422);
                }
                $mongoConf = base64_decode($request->mongo_conf);
                if (mb_detect_encoding($mongoConf, 'UTF-8', true) === false) {
                    return response()->json([
                        'message' => 'Validation failed.',
                        'errors' => [
                            'mongo_conf' => 'The mongo_conf should be base64 encoded.',
                        ],
                    ], 422);
                }
                $request->offsetSet('mongo_conf', $mongoConf);
            }
            $database = create_standalone_mongodb($environment->id, $destination->uuid, $request->all());
            if ($instantDeploy) {
                StartDatabase::dispatch($database);
            }

            $database->refresh();
            $payload = [
                'uuid' => $database->uuid,
                'internal_db_url' => $database->internal_db_url,
            ];
            if ($database->is_public && $database->public_port) {
                $payload['external_db_url'] = $database->external_db_url;
            }

            return response()->json(serializeApiResponse($payload))->setStatusCode(201);
        }

        return response()->json(['message' => 'Invalid database type requested.'], 400);
    }

    #[OA\Delete(
        summary: 'Delete',
        description: 'Delete database by UUID.',
        path: '/databases/{uuid}',
        operationId: 'delete-database-by-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Databases'],
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                in: 'path',
                description: 'UUID of the database.',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                )
            ),
            new OA\Parameter(name: 'delete_configurations', in: 'query', required: false, description: 'Delete configurations.', schema: new OA\Schema(type: 'boolean', default: true)),
            new OA\Parameter(name: 'delete_volumes', in: 'query', required: false, description: 'Delete volumes.', schema: new OA\Schema(type: 'boolean', default: true)),
            new OA\Parameter(name: 'docker_cleanup', in: 'query', required: false, description: 'Run docker cleanup.', schema: new OA\Schema(type: 'boolean', default: true)),
            new OA\Parameter(name: 'delete_connected_networks', in: 'query', required: false, description: 'Delete connected networks.', schema: new OA\Schema(type: 'boolean', default: true)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Database deleted.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'message' => ['type' => 'string', 'example' => 'Database deleted.'],
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
                response: 400,
                ref: '#/components/responses/400',
            ),
            new OA\Response(
                response: 404,
                ref: '#/components/responses/404',
            ),
        ]
    )]
    public function delete_by_uuid(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        if (! $request->uuid) {
            return response()->json(['message' => 'UUID is required.'], 404);
        }
        $database = queryDatabaseByUuidWithinTeam($request->uuid, $teamId);
        if (! $database) {
            return response()->json(['message' => 'Database not found.'], 404);
        }

        $this->authorize('delete', $database);

        DeleteResourceJob::dispatch(
            resource: $database,
            deleteVolumes: $request->boolean('delete_volumes', true),
            deleteConnectedNetworks: $request->boolean('delete_connected_networks', true),
            deleteConfigurations: $request->boolean('delete_configurations', true),
            dockerCleanup: $request->boolean('docker_cleanup', true)
        );

        return response()->json([
            'message' => 'Database deletion request queued.',
        ]);
    }

    #[OA\Delete(
        summary: 'Delete backup configuration',
        description: 'Deletes a backup configuration and all its executions.',
        path: '/databases/{uuid}/backups/{scheduled_backup_uuid}',
        operationId: 'delete-backup-configuration-by-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Databases'],
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                in: 'path',
                required: true,
                description: 'UUID of the database',
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'scheduled_backup_uuid',
                in: 'path',
                required: true,
                description: 'UUID of the backup configuration to delete',
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'delete_s3',
                in: 'query',
                required: false,
                description: 'Whether to delete all backup files from S3',
                schema: new OA\Schema(type: 'boolean', default: false)
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Backup configuration deleted.',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Backup configuration and all executions deleted.'),
                    ]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Backup configuration not found.',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Backup configuration not found.'),
                    ]
                )
            ),
        ]
    )]
    public function delete_backup_by_uuid(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        // Validate scheduled_backup_uuid is provided
        if (! $request->scheduled_backup_uuid) {
            return response()->json(['message' => 'Scheduled backup UUID is required.'], 400);
        }

        $database = queryDatabaseByUuidWithinTeam($request->uuid, $teamId);
        if (! $database) {
            return response()->json(['message' => 'Database not found.'], 404);
        }

        $this->authorize('update', $database);

        // Find the backup configuration by its UUID
        $backup = ScheduledDatabaseBackup::ownedByCurrentTeamAPI($teamId)->where('database_id', $database->id)
            ->where('uuid', $request->scheduled_backup_uuid)
            ->first();

        if (! $backup) {
            return response()->json(['message' => 'Backup configuration not found.'], 404);
        }

        $deleteS3 = $request->boolean('delete_s3', false);

        try {
            DB::beginTransaction();
            // Get all executions for this backup configuration
            $executions = $backup->executions()->get();

            // Delete all execution files (locally and optionally from S3)
            foreach ($executions as $execution) {
                if ($execution->filename) {
                    deleteBackupsLocally($execution->filename, $database->destination->server);

                    if ($deleteS3 && $backup->s3) {
                        deleteBackupsS3($execution->filename, $backup->s3);
                    }
                }

                $execution->delete();
            }

            // Delete the backup configuration itself
            $backup->delete();
            DB::commit();

            return response()->json([
                'message' => 'Backup configuration and all executions deleted.',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['message' => 'Failed to delete backup: '.$e->getMessage()], 500);
        }
    }

    #[OA\Delete(
        summary: 'Delete backup execution',
        description: 'Deletes a specific backup execution.',
        path: '/databases/{uuid}/backups/{scheduled_backup_uuid}/executions/{execution_uuid}',
        operationId: 'delete-backup-execution-by-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Databases'],
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                in: 'path',
                required: true,
                description: 'UUID of the database',
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'scheduled_backup_uuid',
                in: 'path',
                required: true,
                description: 'UUID of the backup configuration',
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'execution_uuid',
                in: 'path',
                required: true,
                description: 'UUID of the backup execution to delete',
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'delete_s3',
                in: 'query',
                required: false,
                description: 'Whether to delete the backup from S3',
                schema: new OA\Schema(type: 'boolean', default: false)
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Backup execution deleted.',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Backup execution deleted.'),
                    ]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Backup execution not found.',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Backup execution not found.'),
                    ]
                )
            ),
        ]
    )]
    public function delete_execution_by_uuid(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        // Validate parameters
        if (! $request->scheduled_backup_uuid) {
            return response()->json(['message' => 'Scheduled backup UUID is required.'], 400);
        }
        if (! $request->execution_uuid) {
            return response()->json(['message' => 'Execution UUID is required.'], 400);
        }

        $database = queryDatabaseByUuidWithinTeam($request->uuid, $teamId);
        if (! $database) {
            return response()->json(['message' => 'Database not found.'], 404);
        }

        $this->authorize('update', $database);

        // Find the backup configuration by its UUID
        $backup = ScheduledDatabaseBackup::ownedByCurrentTeamAPI($teamId)->where('database_id', $database->id)
            ->where('uuid', $request->scheduled_backup_uuid)
            ->first();

        if (! $backup) {
            return response()->json(['message' => 'Backup configuration not found.'], 404);
        }

        // Find the specific execution
        $execution = $backup->executions()->where('uuid', $request->execution_uuid)->first();
        if (! $execution) {
            return response()->json(['message' => 'Backup execution not found.'], 404);
        }

        $deleteS3 = $request->boolean('delete_s3', false);

        try {
            if ($execution->filename) {
                deleteBackupsLocally($execution->filename, $database->destination->server);

                if ($deleteS3 && $backup->s3) {
                    deleteBackupsS3($execution->filename, $backup->s3);
                }
            }

            $execution->delete();

            return response()->json([
                'message' => 'Backup execution deleted.',
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to delete backup execution: '.$e->getMessage()], 500);
        }
    }

    #[OA\Get(
        summary: 'List backup executions',
        description: 'Get all executions for a specific backup configuration.',
        path: '/databases/{uuid}/backups/{scheduled_backup_uuid}/executions',
        operationId: 'list-backup-executions',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Databases'],
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                in: 'path',
                required: true,
                description: 'UUID of the database',
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'scheduled_backup_uuid',
                in: 'path',
                required: true,
                description: 'UUID of the backup configuration',
                schema: new OA\Schema(type: 'string')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of backup executions',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(
                            property: 'executions',
                            type: 'array',
                            items: new OA\Items(
                                type: 'object',
                                properties: [
                                    new OA\Property(property: 'uuid', type: 'string'),
                                    new OA\Property(property: 'filename', type: 'string'),
                                    new OA\Property(property: 'size', type: 'integer'),
                                    new OA\Property(property: 'created_at', type: 'string'),
                                    new OA\Property(property: 'message', type: 'string'),
                                    new OA\Property(property: 'status', type: 'string'),
                                ]
                            )
                        ),
                    ]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Backup configuration not found.',
            ),
        ]
    )]
    public function list_backup_executions(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        // Validate scheduled_backup_uuid is provided
        if (! $request->scheduled_backup_uuid) {
            return response()->json(['message' => 'Scheduled backup UUID is required.'], 400);
        }

        $database = queryDatabaseByUuidWithinTeam($request->uuid, $teamId);
        if (! $database) {
            return response()->json(['message' => 'Database not found.'], 404);
        }

        // Find the backup configuration by its UUID
        $backup = ScheduledDatabaseBackup::ownedByCurrentTeamAPI($teamId)->where('database_id', $database->id)
            ->where('uuid', $request->scheduled_backup_uuid)
            ->first();

        if (! $backup) {
            return response()->json(['message' => 'Backup configuration not found.'], 404);
        }

        // Get all executions for this backup configuration
        $executions = $backup->executions()
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($execution) {
                return [
                    'uuid' => $execution->uuid,
                    'filename' => $execution->filename,
                    'size' => $execution->size,
                    'created_at' => $execution->created_at->toIso8601String(),
                    'message' => $execution->message,
                    'status' => $execution->status,
                ];
            });

        return response()->json([
            'executions' => $executions,
        ]);
    }

    #[OA\Get(
        summary: 'Start',
        description: 'Start database. `Post` request is also accepted.',
        path: '/databases/{uuid}/start',
        operationId: 'start-database-by-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Databases'],
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                in: 'path',
                description: 'UUID of the database.',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                )
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Start database.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'message' => ['type' => 'string', 'example' => 'Database starting request queued.'],
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
                response: 400,
                ref: '#/components/responses/400',
            ),
            new OA\Response(
                response: 404,
                ref: '#/components/responses/404',
            ),
        ]
    )]
    public function action_deploy(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $uuid = $request->route('uuid');
        if (! $uuid) {
            return response()->json(['message' => 'UUID is required.'], 400);
        }
        $database = queryDatabaseByUuidWithinTeam($request->uuid, $teamId);
        if (! $database) {
            return response()->json(['message' => 'Database not found.'], 404);
        }

        $this->authorize('manage', $database);

        if (str($database->status)->contains('running')) {
            return response()->json(['message' => 'Database is already running.'], 400);
        }
        StartDatabase::dispatch($database);

        return response()->json(
            [
                'message' => 'Database starting request queued.',
            ],
            200
        );
    }

    #[OA\Get(
        summary: 'Stop',
        description: 'Stop database. `Post` request is also accepted.',
        path: '/databases/{uuid}/stop',
        operationId: 'stop-database-by-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Databases'],
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                in: 'path',
                description: 'UUID of the database.',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                )
            ),
            new OA\Parameter(
                name: 'docker_cleanup',
                in: 'query',
                description: 'Perform docker cleanup (prune networks, volumes, etc.).',
                schema: new OA\Schema(
                    type: 'boolean',
                    default: true,
                )
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Stop database.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'message' => ['type' => 'string', 'example' => 'Database stopping request queued.'],
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
                response: 400,
                ref: '#/components/responses/400',
            ),
            new OA\Response(
                response: 404,
                ref: '#/components/responses/404',
            ),
        ]
    )]
    public function action_stop(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $uuid = $request->route('uuid');
        if (! $uuid) {
            return response()->json(['message' => 'UUID is required.'], 400);
        }
        $database = queryDatabaseByUuidWithinTeam($request->uuid, $teamId);
        if (! $database) {
            return response()->json(['message' => 'Database not found.'], 404);
        }

        $this->authorize('manage', $database);

        if (str($database->status)->contains('stopped') || str($database->status)->contains('exited')) {
            return response()->json(['message' => 'Database is already stopped.'], 400);
        }

        $dockerCleanup = $request->boolean('docker_cleanup', true);
        StopDatabase::dispatch($database, $dockerCleanup);

        return response()->json(
            [
                'message' => 'Database stopping request queued.',
            ],
            200
        );
    }

    #[OA\Get(
        summary: 'Restart',
        description: 'Restart database. `Post` request is also accepted.',
        path: '/databases/{uuid}/restart',
        operationId: 'restart-database-by-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Databases'],
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                in: 'path',
                description: 'UUID of the database.',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                )
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Restart database.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'message' => ['type' => 'string', 'example' => 'Database restaring request queued.'],
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
                response: 400,
                ref: '#/components/responses/400',
            ),
            new OA\Response(
                response: 404,
                ref: '#/components/responses/404',
            ),
        ]
    )]
    public function action_restart(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $uuid = $request->route('uuid');
        if (! $uuid) {
            return response()->json(['message' => 'UUID is required.'], 400);
        }
        $database = queryDatabaseByUuidWithinTeam($request->uuid, $teamId);
        if (! $database) {
            return response()->json(['message' => 'Database not found.'], 404);
        }

        $this->authorize('manage', $database);

        RestartDatabase::dispatch($database);

        return response()->json(
            [
                'message' => 'Database restarting request queued.',
            ],
            200
        );
    }

    private function removeSensitiveEnvData($env)
    {
        $env->makeHidden([
            'id',
            'resourceable',
            'resourceable_id',
            'resourceable_type',
        ]);
        if (request()->attributes->get('can_read_sensitive', false) === false) {
            $env->makeHidden([
                'value',
                'real_value',
            ]);
        }

        return serializeApiResponse($env);
    }

    #[OA\Get(
        summary: 'List Envs',
        description: 'List all envs by database UUID.',
        path: '/databases/{uuid}/envs',
        operationId: 'list-envs-by-database-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Databases'],
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                in: 'path',
                description: 'UUID of the database.',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                )
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Environment variables.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/EnvironmentVariable')
                        )
                    ),
                ]
            ),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 400,
                ref: '#/components/responses/400',
            ),
            new OA\Response(
                response: 404,
                ref: '#/components/responses/404',
            ),
        ]
    )]
    public function envs(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $database = queryDatabaseByUuidWithinTeam($request->uuid, $teamId);
        if (! $database) {
            return response()->json(['message' => 'Database not found.'], 404);
        }

        $this->authorize('view', $database);

        $envs = $database->environment_variables->map(function ($env) {
            return $this->removeSensitiveEnvData($env);
        });

        return response()->json($envs);
    }

    #[OA\Patch(
        summary: 'Update Env',
        description: 'Update env by database UUID.',
        path: '/databases/{uuid}/envs',
        operationId: 'update-env-by-database-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Databases'],
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                in: 'path',
                description: 'UUID of the database.',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                )
            ),
        ],
        requestBody: new OA\RequestBody(
            description: 'Env updated.',
            required: true,
            content: [
                new OA\MediaType(
                    mediaType: 'application/json',
                    schema: new OA\Schema(
                        type: 'object',
                        required: ['key', 'value'],
                        properties: [
                            'key' => ['type' => 'string', 'description' => 'The key of the environment variable.'],
                            'value' => ['type' => 'string', 'description' => 'The value of the environment variable.'],
                            'is_literal' => ['type' => 'boolean', 'description' => 'The flag to indicate if the environment variable is a literal, nothing espaced.'],
                            'is_multiline' => ['type' => 'boolean', 'description' => 'The flag to indicate if the environment variable is multiline.'],
                            'is_shown_once' => ['type' => 'boolean', 'description' => 'The flag to indicate if the environment variable\'s value is shown on the UI.'],
                        ],
                    ),
                ),
            ],
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Environment variable updated.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            ref: '#/components/schemas/EnvironmentVariable'
                        )
                    ),
                ]
            ),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 400,
                ref: '#/components/responses/400',
            ),
            new OA\Response(
                response: 404,
                ref: '#/components/responses/404',
            ),
            new OA\Response(
                response: 422,
                ref: '#/components/responses/422',
            ),
        ]
    )]
    public function update_env_by_uuid(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $database = queryDatabaseByUuidWithinTeam($request->route('uuid'), $teamId);
        if (! $database) {
            return response()->json(['message' => 'Database not found.'], 404);
        }

        $this->authorize('manageEnvironment', $database);

        $validator = customApiValidator($request->all(), [
            'key' => 'string|required',
            'value' => 'string|nullable',
            'is_literal' => 'boolean',
            'is_multiline' => 'boolean',
            'is_shown_once' => 'boolean',
            'comment' => 'string|nullable|max:256',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $key = str($request->key)->trim()->replace(' ', '_')->value;
        $env = $database->environment_variables()->where('key', $key)->first();
        if (! $env) {
            return response()->json(['message' => 'Environment variable not found.'], 404);
        }

        $env->value = $request->value;
        if ($request->has('is_literal')) {
            $env->is_literal = $request->is_literal;
        }
        if ($request->has('is_multiline')) {
            $env->is_multiline = $request->is_multiline;
        }
        if ($request->has('is_shown_once')) {
            $env->is_shown_once = $request->is_shown_once;
        }
        if ($request->has('comment')) {
            $env->comment = $request->comment;
        }
        $env->save();

        return response()->json($this->removeSensitiveEnvData($env))->setStatusCode(201);
    }

    #[OA\Patch(
        summary: 'Update Envs (Bulk)',
        description: 'Update multiple envs by database UUID.',
        path: '/databases/{uuid}/envs/bulk',
        operationId: 'update-envs-by-database-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Databases'],
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                in: 'path',
                description: 'UUID of the database.',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                )
            ),
        ],
        requestBody: new OA\RequestBody(
            description: 'Bulk envs updated.',
            required: true,
            content: [
                new OA\MediaType(
                    mediaType: 'application/json',
                    schema: new OA\Schema(
                        type: 'object',
                        required: ['data'],
                        properties: [
                            'data' => [
                                'type' => 'array',
                                'items' => new OA\Schema(
                                    type: 'object',
                                    properties: [
                                        'key' => ['type' => 'string', 'description' => 'The key of the environment variable.'],
                                        'value' => ['type' => 'string', 'description' => 'The value of the environment variable.'],
                                        'is_literal' => ['type' => 'boolean', 'description' => 'The flag to indicate if the environment variable is a literal, nothing espaced.'],
                                        'is_multiline' => ['type' => 'boolean', 'description' => 'The flag to indicate if the environment variable is multiline.'],
                                        'is_shown_once' => ['type' => 'boolean', 'description' => 'The flag to indicate if the environment variable\'s value is shown on the UI.'],
                                    ],
                                ),
                            ],
                        ],
                    ),
                ),
            ],
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Environment variables updated.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/EnvironmentVariable')
                        )
                    ),
                ]
            ),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 400,
                ref: '#/components/responses/400',
            ),
            new OA\Response(
                response: 404,
                ref: '#/components/responses/404',
            ),
            new OA\Response(
                response: 422,
                ref: '#/components/responses/422',
            ),
        ]
    )]
    public function create_bulk_envs(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $database = queryDatabaseByUuidWithinTeam($request->route('uuid'), $teamId);
        if (! $database) {
            return response()->json(['message' => 'Database not found.'], 404);
        }

        $this->authorize('manageEnvironment', $database);

        $bulk_data = $request->get('data');
        if (! $bulk_data) {
            return response()->json(['message' => 'Bulk data is required.'], 400);
        }

        $updatedEnvs = collect();
        foreach ($bulk_data as $item) {
            $validator = customApiValidator($item, [
                'key' => 'string|required',
                'value' => 'string|nullable',
                'is_literal' => 'boolean',
                'is_multiline' => 'boolean',
                'is_shown_once' => 'boolean',
                'comment' => 'string|nullable|max:256',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => $validator->errors(),
                ], 422);
            }
            $key = str($item['key'])->trim()->replace(' ', '_')->value;
            $env = $database->environment_variables()->updateOrCreate(
                ['key' => $key],
                $item
            );

            $updatedEnvs->push($this->removeSensitiveEnvData($env));
        }

        return response()->json($updatedEnvs)->setStatusCode(201);
    }

    #[OA\Post(
        summary: 'Create Env',
        description: 'Create env by database UUID.',
        path: '/databases/{uuid}/envs',
        operationId: 'create-env-by-database-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Databases'],
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                in: 'path',
                description: 'UUID of the database.',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                )
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            description: 'Env created.',
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    type: 'object',
                    properties: [
                        'key' => ['type' => 'string', 'description' => 'The key of the environment variable.'],
                        'value' => ['type' => 'string', 'description' => 'The value of the environment variable.'],
                        'is_literal' => ['type' => 'boolean', 'description' => 'The flag to indicate if the environment variable is a literal, nothing espaced.'],
                        'is_multiline' => ['type' => 'boolean', 'description' => 'The flag to indicate if the environment variable is multiline.'],
                        'is_shown_once' => ['type' => 'boolean', 'description' => 'The flag to indicate if the environment variable\'s value is shown on the UI.'],
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Environment variable created.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'uuid' => ['type' => 'string', 'example' => 'nc0k04gk8g0cgsk440g0koko'],
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
                response: 400,
                ref: '#/components/responses/400',
            ),
            new OA\Response(
                response: 404,
                ref: '#/components/responses/404',
            ),
            new OA\Response(
                response: 422,
                ref: '#/components/responses/422',
            ),
        ]
    )]
    public function create_env(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $database = queryDatabaseByUuidWithinTeam($request->route('uuid'), $teamId);
        if (! $database) {
            return response()->json(['message' => 'Database not found.'], 404);
        }

        $this->authorize('manageEnvironment', $database);

        $validator = customApiValidator($request->all(), [
            'key' => 'string|required',
            'value' => 'string|nullable',
            'is_literal' => 'boolean',
            'is_multiline' => 'boolean',
            'is_shown_once' => 'boolean',
            'comment' => 'string|nullable|max:256',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $key = str($request->key)->trim()->replace(' ', '_')->value;
        $existingEnv = $database->environment_variables()->where('key', $key)->first();
        if ($existingEnv) {
            return response()->json([
                'message' => 'Environment variable already exists. Use PATCH request to update it.',
            ], 409);
        }

        $env = $database->environment_variables()->create([
            'key' => $key,
            'value' => $request->value,
            'is_literal' => $request->is_literal ?? false,
            'is_multiline' => $request->is_multiline ?? false,
            'is_shown_once' => $request->is_shown_once ?? false,
            'comment' => $request->comment ?? null,
        ]);

        return response()->json($this->removeSensitiveEnvData($env))->setStatusCode(201);
    }

    #[OA\Delete(
        summary: 'Delete Env',
        description: 'Delete env by UUID.',
        path: '/databases/{uuid}/envs/{env_uuid}',
        operationId: 'delete-env-by-database-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Databases'],
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                in: 'path',
                description: 'UUID of the database.',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                )
            ),
            new OA\Parameter(
                name: 'env_uuid',
                in: 'path',
                description: 'UUID of the environment variable.',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                )
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Environment variable deleted.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'message' => ['type' => 'string', 'example' => 'Environment variable deleted.'],
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
                response: 400,
                ref: '#/components/responses/400',
            ),
            new OA\Response(
                response: 404,
                ref: '#/components/responses/404',
            ),
        ]
    )]
    public function delete_env_by_uuid(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $database = queryDatabaseByUuidWithinTeam($request->route('uuid'), $teamId);
        if (! $database) {
            return response()->json(['message' => 'Database not found.'], 404);
        }

        $this->authorize('manageEnvironment', $database);

        $env = EnvironmentVariable::where('uuid', $request->route('env_uuid'))
            ->where('resourceable_type', get_class($database))
            ->where('resourceable_id', $database->id)
            ->first();

        if (! $env) {
            return response()->json(['message' => 'Environment variable not found.'], 404);
        }

        $env->forceDelete();

        return response()->json(['message' => 'Environment variable deleted.']);
    }

    #[OA\Get(
        summary: 'List Storages',
        description: 'List all persistent storages and file storages by database UUID.',
        path: '/databases/{uuid}/storages',
        operationId: 'list-storages-by-database-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Databases'],
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                in: 'path',
                description: 'UUID of the database.',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                )
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'All storages by database UUID.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'persistent_storages', type: 'array', items: new OA\Items(type: 'object')),
                        new OA\Property(property: 'file_storages', type: 'array', items: new OA\Items(type: 'object')),
                    ],
                ),
            ),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 400,
                ref: '#/components/responses/400',
            ),
            new OA\Response(
                response: 404,
                ref: '#/components/responses/404',
            ),
        ]
    )]
    public function storages(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $database = queryDatabaseByUuidWithinTeam($request->uuid, $teamId);
        if (! $database) {
            return response()->json(['message' => 'Database not found.'], 404);
        }

        $this->authorize('view', $database);

        $persistentStorages = $database->persistentStorages->sortBy('id')->values();
        $fileStorages = $database->fileStorages->sortBy('id')->values();

        return response()->json([
            'persistent_storages' => $persistentStorages,
            'file_storages' => $fileStorages,
        ]);
    }

    #[OA\Post(
        summary: 'Create Storage',
        description: 'Create a persistent storage or file storage for a database.',
        path: '/databases/{uuid}/storages',
        operationId: 'create-storage-by-database-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Databases'],
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                in: 'path',
                description: 'UUID of the database.',
                required: true,
                schema: new OA\Schema(type: 'string')
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: [
                new OA\MediaType(
                    mediaType: 'application/json',
                    schema: new OA\Schema(
                        type: 'object',
                        required: ['type', 'mount_path'],
                        properties: [
                            'type' => ['type' => 'string', 'enum' => ['persistent', 'file'], 'description' => 'The type of storage.'],
                            'name' => ['type' => 'string', 'description' => 'Volume name (persistent only, required for persistent).'],
                            'mount_path' => ['type' => 'string', 'description' => 'The container mount path.'],
                            'host_path' => ['type' => 'string', 'nullable' => true, 'description' => 'The host path (persistent only, optional).'],
                            'content' => ['type' => 'string', 'nullable' => true, 'description' => 'File content (file only, optional).'],
                            'is_directory' => ['type' => 'boolean', 'description' => 'Whether this is a directory mount (file only, default false).'],
                            'fs_path' => ['type' => 'string', 'description' => 'Host directory path (required when is_directory is true).'],
                        ],
                        additionalProperties: false,
                    ),
                ),
            ],
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Storage created.',
                content: new OA\JsonContent(type: 'object'),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function create_storage(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $return = validateIncomingRequest($request);
        if ($return instanceof JsonResponse) {
            return $return;
        }

        $database = queryDatabaseByUuidWithinTeam($request->uuid, $teamId);
        if (! $database) {
            return response()->json(['message' => 'Database not found.'], 404);
        }

        $this->authorize('update', $database);

        $validator = customApiValidator($request->all(), [
            'type' => 'required|string|in:persistent,file',
            'name' => 'string',
            'mount_path' => 'required|string',
            'host_path' => 'string|nullable',
            'content' => 'string|nullable',
            'is_directory' => 'boolean',
            'fs_path' => 'string',
        ]);

        $allAllowedFields = ['type', 'name', 'mount_path', 'host_path', 'content', 'is_directory', 'fs_path'];
        $extraFields = array_diff(array_keys($request->all()), $allAllowedFields);
        if ($validator->fails() || ! empty($extraFields)) {
            $errors = $validator->errors();
            if (! empty($extraFields)) {
                foreach ($extraFields as $field) {
                    $errors->add($field, 'This field is not allowed.');
                }
            }

            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $errors,
            ], 422);
        }

        if ($request->type === 'persistent') {
            if (! $request->name) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => ['name' => 'The name field is required for persistent storages.'],
                ], 422);
            }

            $typeSpecificInvalidFields = array_intersect(['content', 'is_directory', 'fs_path'], array_keys($request->all()));
            if (! empty($typeSpecificInvalidFields)) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => collect($typeSpecificInvalidFields)
                        ->mapWithKeys(fn ($field) => [$field => "Field '{$field}' is not valid for type 'persistent'."]),
                ], 422);
            }

            $storage = LocalPersistentVolume::create([
                'name' => $database->uuid.'-'.$request->name,
                'mount_path' => $request->mount_path,
                'host_path' => $request->host_path,
                'resource_id' => $database->id,
                'resource_type' => $database->getMorphClass(),
            ]);

            return response()->json($storage, 201);
        }

        // File storage
        $typeSpecificInvalidFields = array_intersect(['name', 'host_path'], array_keys($request->all()));
        if (! empty($typeSpecificInvalidFields)) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => collect($typeSpecificInvalidFields)
                    ->mapWithKeys(fn ($field) => [$field => "Field '{$field}' is not valid for type 'file'."]),
            ], 422);
        }

        $isDirectory = $request->boolean('is_directory', false);

        if ($isDirectory) {
            if (! $request->fs_path) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => ['fs_path' => 'The fs_path field is required for directory mounts.'],
                ], 422);
            }

            $fsPath = str($request->fs_path)->trim()->start('/')->value();
            $mountPath = str($request->mount_path)->trim()->start('/')->value();

            validateShellSafePath($fsPath, 'storage source path');
            validateShellSafePath($mountPath, 'storage destination path');

            $storage = LocalFileVolume::create([
                'fs_path' => $fsPath,
                'mount_path' => $mountPath,
                'is_directory' => true,
                'resource_id' => $database->id,
                'resource_type' => get_class($database),
            ]);
        } else {
            $mountPath = str($request->mount_path)->trim()->start('/')->value();
            $fsPath = database_configuration_dir().'/'.$database->uuid.$mountPath;

            $storage = LocalFileVolume::create([
                'fs_path' => $fsPath,
                'mount_path' => $mountPath,
                'content' => $request->content,
                'is_directory' => false,
                'resource_id' => $database->id,
                'resource_type' => get_class($database),
            ]);
        }

        return response()->json($storage, 201);
    }

    #[OA\Patch(
        summary: 'Update Storage',
        description: 'Update a persistent storage or file storage by database UUID.',
        path: '/databases/{uuid}/storages',
        operationId: 'update-storage-by-database-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Databases'],
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                in: 'path',
                description: 'UUID of the database.',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                )
            ),
        ],
        requestBody: new OA\RequestBody(
            description: 'Storage updated. For read-only storages (from docker-compose or services), only is_preview_suffix_enabled can be updated.',
            required: true,
            content: [
                new OA\MediaType(
                    mediaType: 'application/json',
                    schema: new OA\Schema(
                        type: 'object',
                        required: ['type'],
                        properties: [
                            'uuid' => ['type' => 'string', 'description' => 'The UUID of the storage (preferred).'],
                            'id' => ['type' => 'integer', 'description' => 'The ID of the storage (deprecated, use uuid instead).'],
                            'type' => ['type' => 'string', 'enum' => ['persistent', 'file'], 'description' => 'The type of storage: persistent or file.'],
                            'is_preview_suffix_enabled' => ['type' => 'boolean', 'description' => 'Whether to add -pr-N suffix for preview deployments.'],
                            'name' => ['type' => 'string', 'description' => 'The volume name (persistent only, not allowed for read-only storages).'],
                            'mount_path' => ['type' => 'string', 'description' => 'The container mount path (not allowed for read-only storages).'],
                            'host_path' => ['type' => 'string', 'nullable' => true, 'description' => 'The host path (persistent only, not allowed for read-only storages).'],
                            'content' => ['type' => 'string', 'nullable' => true, 'description' => 'The file content (file only, not allowed for read-only storages).'],
                        ],
                        additionalProperties: false,
                    ),
                ),
            ],
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Storage updated.',
                content: new OA\JsonContent(type: 'object'),
            ),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 400,
                ref: '#/components/responses/400',
            ),
            new OA\Response(
                response: 404,
                ref: '#/components/responses/404',
            ),
            new OA\Response(
                response: 422,
                ref: '#/components/responses/422',
            ),
        ]
    )]
    public function update_storage(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();

        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $return = validateIncomingRequest($request);
        if ($return instanceof \Illuminate\Http\JsonResponse) {
            return $return;
        }

        $database = queryDatabaseByUuidWithinTeam($request->route('uuid'), $teamId);
        if (! $database) {
            return response()->json(['message' => 'Database not found.'], 404);
        }

        $this->authorize('update', $database);

        $validator = customApiValidator($request->all(), [
            'uuid' => 'string',
            'id' => 'integer',
            'type' => 'required|string|in:persistent,file',
            'is_preview_suffix_enabled' => 'boolean',
            'name' => 'string',
            'mount_path' => 'string',
            'host_path' => 'string|nullable',
            'content' => 'string|nullable',
        ]);

        $allAllowedFields = ['uuid', 'id', 'type', 'is_preview_suffix_enabled', 'name', 'mount_path', 'host_path', 'content'];
        $extraFields = array_diff(array_keys($request->all()), $allAllowedFields);
        if ($validator->fails() || ! empty($extraFields)) {
            $errors = $validator->errors();
            if (! empty($extraFields)) {
                foreach ($extraFields as $field) {
                    $errors->add($field, 'This field is not allowed.');
                }
            }

            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $errors,
            ], 422);
        }

        $storageUuid = $request->input('uuid');
        $storageId = $request->input('id');

        if (! $storageUuid && ! $storageId) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => ['uuid' => 'Either uuid or id is required.'],
            ], 422);
        }

        $lookupField = $storageUuid ? 'uuid' : 'id';
        $lookupValue = $storageUuid ?? $storageId;

        if ($request->type === 'persistent') {
            $storage = $database->persistentStorages->where($lookupField, $lookupValue)->first();
        } else {
            $storage = $database->fileStorages->where($lookupField, $lookupValue)->first();
        }

        if (! $storage) {
            return response()->json([
                'message' => 'Storage not found.',
            ], 404);
        }

        $isReadOnly = $storage->shouldBeReadOnlyInUI();
        $editableOnlyFields = ['name', 'mount_path', 'host_path', 'content'];
        $requestedEditableFields = array_intersect($editableOnlyFields, array_keys($request->all()));

        if ($isReadOnly && ! empty($requestedEditableFields)) {
            return response()->json([
                'message' => 'This storage is read-only (managed by docker-compose or service definition). Only is_preview_suffix_enabled can be updated.',
                'read_only_fields' => array_values($requestedEditableFields),
            ], 422);
        }

        // Reject fields that don't apply to the given storage type
        if (! $isReadOnly) {
            $typeSpecificInvalidFields = $request->type === 'persistent'
                ? array_intersect(['content'], array_keys($request->all()))
                : array_intersect(['name', 'host_path'], array_keys($request->all()));

            if (! empty($typeSpecificInvalidFields)) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => collect($typeSpecificInvalidFields)
                        ->mapWithKeys(fn ($field) => [$field => "Field '{$field}' is not valid for type '{$request->type}'."]),
                ], 422);
            }
        }

        // Always allowed
        if ($request->has('is_preview_suffix_enabled')) {
            $storage->is_preview_suffix_enabled = $request->is_preview_suffix_enabled;
        }

        // Only for editable storages
        if (! $isReadOnly) {
            if ($request->type === 'persistent') {
                if ($request->has('name')) {
                    $storage->name = $request->name;
                }
                if ($request->has('mount_path')) {
                    $storage->mount_path = $request->mount_path;
                }
                if ($request->has('host_path')) {
                    $storage->host_path = $request->host_path;
                }
            } else {
                if ($request->has('mount_path')) {
                    $storage->mount_path = $request->mount_path;
                }
                if ($request->has('content')) {
                    $storage->content = $request->content;
                }
            }
        }

        $storage->save();

        return response()->json($storage);
    }

    #[OA\Delete(
        summary: 'Delete Storage',
        description: 'Delete a persistent storage or file storage by database UUID.',
        path: '/databases/{uuid}/storages/{storage_uuid}',
        operationId: 'delete-storage-by-database-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Databases'],
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                in: 'path',
                description: 'UUID of the database.',
                required: true,
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'storage_uuid',
                in: 'path',
                description: 'UUID of the storage.',
                required: true,
                schema: new OA\Schema(type: 'string')
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Storage deleted.', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'message', type: 'string')],
            )),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function delete_storage(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $database = queryDatabaseByUuidWithinTeam($request->uuid, $teamId);
        if (! $database) {
            return response()->json(['message' => 'Database not found.'], 404);
        }

        $this->authorize('update', $database);

        $storageUuid = $request->route('storage_uuid');

        $storage = $database->persistentStorages->where('uuid', $storageUuid)->first();
        if (! $storage) {
            $storage = $database->fileStorages->where('uuid', $storageUuid)->first();
        }

        if (! $storage) {
            return response()->json(['message' => 'Storage not found.'], 404);
        }

        if ($storage->shouldBeReadOnlyInUI()) {
            return response()->json([
                'message' => 'This storage is read-only (managed by docker-compose or service definition) and cannot be deleted.',
            ], 422);
        }

        if ($storage instanceof LocalFileVolume) {
            $storage->deleteStorageOnServer();
        }

        $storage->delete();

        return response()->json(['message' => 'Storage deleted.']);
    }
}
