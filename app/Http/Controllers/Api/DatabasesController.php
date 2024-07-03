<?php

namespace App\Http\Controllers\Api;

use App\Actions\Database\RestartDatabase;
use App\Actions\Database\StartDatabase;
use App\Actions\Database\StartDatabaseProxy;
use App\Actions\Database\StopDatabase;
use App\Actions\Database\StopDatabaseProxy;
use App\Enums\NewDatabaseTypes;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Server;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DatabasesController extends Controller
{
    private function removeSensitiveData($database)
    {
        $token = auth()->user()->currentAccessToken();
        if ($token->can('view:sensitive')) {
            return serializeApiResponse($database);
        }

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

        return serializeApiResponse($database);
    }

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

    public function update_by_uuid(Request $request)
    {
        $allowedFields = ['name', 'description', 'image', 'public_port', 'is_public', 'instant_deploy', 'limits_memory', 'limits_memory_swap', 'limits_memory_swappiness', 'limits_memory_reservation', 'limits_cpus', 'limits_cpuset', 'limits_cpu_shares', 'postgres_user', 'postgres_password', 'postgres_db', 'postgres_initdb_args', 'postgres_host_auth_method', 'postgres_conf', 'clickhouse_admin_user', 'clickhouse_admin_password', 'dragonfly_password', 'redis_password', 'redis_conf', 'keydb_password', 'keydb_conf', 'mariadb_conf', 'mariadb_root_password', 'mariadb_user', 'mariadb_password', 'mariadb_database', 'mongo_conf', 'mongo_initdb_root_username', 'mongo_initdb_root_password', 'mongo_initdb_init_database', 'mysql_root_password', 'mysql_user', 'mysql_database', 'mysql_conf'];
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
            'postgres_user' => 'string',
            'postgres_password' => 'string',
            'postgres_db' => 'string',
            'postgres_initdb_args' => 'string',
            'postgres_host_auth_method' => 'string',
            'postgres_conf' => 'string',
            'clickhouse_admin_user' => 'string',
            'clickhouse_admin_password' => 'string',
            'dragonfly_password' => 'string',
            'redis_password' => 'string',
            'redis_conf' => 'string',
            'keydb_password' => 'string',
            'keydb_conf' => 'string',
            'mariadb_conf' => 'string',
            'mariadb_root_password' => 'string',
            'mariadb_user' => 'string',
            'mariadb_password' => 'string',
            'mariadb_database' => 'string',
            'mongo_conf' => 'string',
            'mongo_initdb_root_username' => 'string',
            'mongo_initdb_root_password' => 'string',
            'mongo_initdb_init_database' => 'string',
            'mysql_root_password' => 'string',
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
            'data' => $this->removeSensitiveData($database),
        ]);

    }

    public function create_database(Request $request)
    {
        $allowedFields = ['type', 'name', 'description', 'image', 'public_port', 'is_public', 'project_uuid', 'environment_name', 'server_uuid', 'destination_uuid', 'instant_deploy', 'limits_memory', 'limits_memory_swap', 'limits_memory_swappiness', 'limits_memory_reservation', 'limits_cpus', 'limits_cpuset', 'limits_cpu_shares', 'postgres_user', 'postgres_password', 'postgres_db', 'postgres_initdb_args', 'postgres_host_auth_method', 'postgres_conf', 'clickhouse_admin_user', 'clickhouse_admin_password', 'dragonfly_password', 'redis_password', 'redis_conf', 'keydb_password', 'keydb_conf', 'mariadb_conf', 'mariadb_root_password', 'mariadb_user', 'mariadb_password', 'mariadb_database', 'mongo_conf', 'mongo_initdb_root_username', 'mongo_initdb_root_password', 'mongo_initdb_init_database', 'mysql_root_password', 'mysql_user', 'mysql_database', 'mysql_conf'];

        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $return = validateIncomingRequest($request);
        if ($return instanceof \Illuminate\Http\JsonResponse) {
            return $return;
        }
        $validator = customApiValidator($request->all(), [
            'type' => ['required', Rule::enum(NewDatabaseTypes::class)],
            'name' => 'string|max:255',
            'description' => 'string|nullable',
            'image' => 'string',
            'project_uuid' => 'string|required',
            'environment_name' => 'string|required',
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
            'postgres_user' => 'string',
            'postgres_password' => 'string',
            'postgres_db' => 'string',
            'postgres_initdb_args' => 'string',
            'postgres_host_auth_method' => 'string',
            'postgres_conf' => 'string',
            'clickhouse_admin_user' => 'string',
            'clickhouse_admin_password' => 'string',
            'dragonfly_password' => 'string',
            'redis_password' => 'string',
            'redis_conf' => 'string',
            'keydb_password' => 'string',
            'keydb_conf' => 'string',
            'mariadb_conf' => 'string',
            'mariadb_root_password' => 'string',
            'mariadb_user' => 'string',
            'mariadb_password' => 'string',
            'mariadb_database' => 'string',
            'mongo_conf' => 'string',
            'mongo_initdb_root_username' => 'string',
            'mongo_initdb_root_password' => 'string',
            'mongo_initdb_init_database' => 'string',
            'mysql_root_password' => 'string',
            'mysql_user' => 'string',
            'mysql_database' => 'string',
            'mysql_conf' => 'string',
            'instant_deploy' => 'boolean',
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
        $serverUuid = $request->server_uuid;
        $instantDeploy = $request->instant_deploy ?? false;
        if ($request->is_public && ! $request->public_port) {
            $request->offsetSet('is_public', false);
        }
        $project = Project::whereTeamId($teamId)->whereUuid($request->project_uuid)->first();
        if (! $project) {
            return response()->json(['message' => 'Project not found.'], 404);
        }
        $environment = $project->environments()->where('name', $request->environment_name)->first();
        if (! $environment) {
            return response()->json(['message' => 'Environment not found.'], 404);
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
        if ($request->type === NewDatabaseTypes::POSTGRESQL->value) {
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
                if ($request->is_public && $request->public_port) {
                    StartDatabaseProxy::dispatch($database);
                }
            }

            return response()->json([
                'message' => 'Database starting queued.',
                'data' => serializeApiResponse($database),
            ]);
        } elseif ($request->type === NewDatabaseTypes::MARIADB->value) {
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
                if ($request->is_public && $request->public_port) {
                    StartDatabaseProxy::dispatch($database);
                }
            }

            return response()->json([
                'message' => 'Database starting queued.',
                'data' => serializeApiResponse($database),
            ]);
        } elseif ($request->type === NewDatabaseTypes::MYSQL->value) {
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
                if ($request->is_public && $request->public_port) {
                    StartDatabaseProxy::dispatch($database);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Database starting queued.',
                'data' => serializeApiResponse($database),
            ]);
        } elseif ($request->type === NewDatabaseTypes::REDIS->value) {
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
                if ($request->is_public && $request->public_port) {
                    StartDatabaseProxy::dispatch($database);
                }
            }

            return response()->json([
                'message' => 'Database starting queued.',
                'data' => serializeApiResponse($database),
            ]);
        } elseif ($request->type === NewDatabaseTypes::DRAGONFLY->value) {
            removeUnnecessaryFieldsFromRequest($request);
            $database = create_standalone_dragonfly($environment->id, $destination->uuid, $request->all());
            if ($instantDeploy) {
                StartDatabase::dispatch($database);
                if ($request->is_public && $request->public_port) {
                    StartDatabaseProxy::dispatch($database);
                }
            }

            return response()->json([
                'message' => 'Database starting queued.',
                'data' => serializeApiResponse($database),
            ]);
        } elseif ($request->type === NewDatabaseTypes::KEYDB->value) {
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
                if ($request->is_public && $request->public_port) {
                    StartDatabaseProxy::dispatch($database);
                }
            }

            return response()->json([
                'message' => 'Database starting queued.',
                'data' => serializeApiResponse($database),
            ]);
        } elseif ($request->type === NewDatabaseTypes::CLICKHOUSE->value) {
            removeUnnecessaryFieldsFromRequest($request);
            $database = create_standalone_clickhouse($environment->id, $destination->uuid, $request->all());
            if ($instantDeploy) {
                StartDatabase::dispatch($database);
                if ($request->is_public && $request->public_port) {
                    StartDatabaseProxy::dispatch($database);
                }
            }

            return response()->json([
                'message' => 'Database starting queued.',
                'data' => serializeApiResponse($database),
            ]);
        } elseif ($request->type === NewDatabaseTypes::MONGODB->value) {
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
                if ($request->is_public && $request->public_port) {
                    StartDatabaseProxy::dispatch($database);
                }
            }

            return response()->json([
                'message' => 'Database starting queued.',
                'data' => serializeApiResponse($database),
            ]);
        }

        return response()->json(['message' => 'Invalid database type requested.'], 400);
    }

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
        StopDatabase::dispatch($database);
        $database->forceDelete();

        return response()->json([
            'message' => 'Database deletion request queued.',
        ]);
    }

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
