<?php

namespace App\Http\Controllers\Api;

use App\Actions\Database\RestartDatabase;
use App\Actions\Database\StartDatabase;
use App\Actions\Database\StartDatabaseProxy;
use App\Actions\Database\StopDatabase;
use App\Actions\Database\StopDatabaseProxy;
use App\Enums\NewDatabaseTypes;
use App\Http\Controllers\Controller;
use App\Jobs\DeleteResourceJob;
use App\Models\Project;
use App\Models\Server;
use Illuminate\Http\Request;
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
        $databases = $databases->map(function ($database) {
            return $this->removeSensitiveData($database);
        });

        return response()->json($databases);
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
                    format: 'uuid',
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
                    format: 'uuid',
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
        ]
    )]
    public function update_by_uuid(Request $request)
    {
        $allowedFields = ['name', 'description', 'image', 'public_port', 'is_public', 'instant_deploy', 'limits_memory', 'limits_memory_swap', 'limits_memory_swappiness', 'limits_memory_reservation', 'limits_cpus', 'limits_cpuset', 'limits_cpu_shares', 'postgres_user', 'postgres_password', 'postgres_db', 'postgres_initdb_args', 'postgres_host_auth_method', 'postgres_conf', 'clickhouse_admin_user', 'clickhouse_admin_password', 'dragonfly_password', 'redis_password', 'redis_conf', 'keydb_password', 'keydb_conf', 'mariadb_conf', 'mariadb_root_password', 'mariadb_user', 'mariadb_password', 'mariadb_database', 'mongo_conf', 'mongo_initdb_root_username', 'mongo_initdb_root_password', 'mongo_initdb_database', 'mysql_root_password', 'mysql_password', 'mysql_user', 'mysql_database', 'mysql_conf'];
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

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
                    if (mb_detect_encoding($postgresConf, 'ASCII', true) === false) {
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
                    if (mb_detect_encoding($redisConf, 'ASCII', true) === false) {
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
                    if (mb_detect_encoding($keydbConf, 'ASCII', true) === false) {
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
                    if (mb_detect_encoding($mariadbConf, 'ASCII', true) === false) {
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
                    if (mb_detect_encoding($mongoConf, 'ASCII', true) === false) {
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
                    if (mb_detect_encoding($mysqlConf, 'ASCII', true) === false) {
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

        $database->update($request->all());

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
                if (mb_detect_encoding($postgresConf, 'ASCII', true) === false) {
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
                if (mb_detect_encoding($mariadbConf, 'ASCII', true) === false) {
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
                if (mb_detect_encoding($mysqlConf, 'ASCII', true) === false) {
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
                if (mb_detect_encoding($redisConf, 'ASCII', true) === false) {
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
                if (mb_detect_encoding($keydbConf, 'ASCII', true) === false) {
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
                if (mb_detect_encoding($mongoConf, 'ASCII', true) === false) {
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
                    format: 'uuid',
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
        $cleanup = filter_var($request->query->get('cleanup', true), FILTER_VALIDATE_BOOLEAN);
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

        DeleteResourceJob::dispatch(
            resource: $database,
            deleteConfigurations: $request->query->get('delete_configurations', true),
            deleteVolumes: $request->query->get('delete_volumes', true),
            dockerCleanup: $request->query->get('docker_cleanup', true),
            deleteConnectedNetworks: $request->query->get('delete_connected_networks', true)
        );

        return response()->json([
            'message' => 'Database deletion request queued.',
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
                    format: 'uuid',
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
                    format: 'uuid',
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
        if (str($database->status)->contains('stopped') || str($database->status)->contains('exited')) {
            return response()->json(['message' => 'Database is already stopped.'], 400);
        }
        StopDatabase::dispatch($database);

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
                    format: 'uuid',
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
        RestartDatabase::dispatch($database);

        return response()->json(
            [
                'message' => 'Database restarting request queued.',
            ],
            200
        );
    }
}
