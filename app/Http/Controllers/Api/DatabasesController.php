<?php

namespace App\Http\Controllers\Api;

use App\Actions\Database\StartClickhouse;
use App\Actions\Database\StartDragonfly;
use App\Actions\Database\StartKeydb;
use App\Actions\Database\StartMariadb;
use App\Actions\Database\StartMongodb;
use App\Actions\Database\StartMysql;
use App\Actions\Database\StartPostgresql;
use App\Actions\Database\StartRedis;
use App\Actions\Database\StopDatabase;
use App\Enums\NewDatabaseTypes;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Server;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DatabasesController extends Controller
{
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
            return serializeApiResponse($database);
        });

        return response()->json([
            'success' => true,
            'data' => $databases,
        ]);
    }

    public function database_by_uuid(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        if (! $request->uuid) {
            return response()->json(['success' => false, 'message' => 'UUID is required.'], 404);
        }
        $database = queryDatabaseByUuidWithinTeam($request->uuid, $teamId);
        if (! $database) {
            return response()->json(['success' => false, 'message' => 'Database not found.'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => serializeApiResponse($database),
        ]);
    }

    public function create_database(Request $request)
    {
        $allowedFields = ['type', 'name', 'description', 'image', 'project_uuid', 'environment_name', 'server_uuid', 'destination_uuid', 'instant_deploy', 'postgres_user', 'postgres_password', 'postgres_db', 'limits_memory', 'limits_memory_swap', 'limits_memory_swappiness', 'limits_memory_reservation', 'limits_cpus', 'limits_cpuset', 'limits_cpu_shares'];
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
            'postgres_user' => 'string',
            'postgres_password' => 'string',
            'postgres_db' => 'string',
            'limits_memory' => 'string',
            'limits_memory_swap' => 'string',
            'limits_memory_swappiness' => 'numeric',
            'limits_memory_reservation' => 'string',
            'limits_cpus' => 'string',
            'limits_cpuset' => 'string|nullable',
            'limits_cpu_shares' => 'numeric',
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
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $errors,
            ], 422);
        }
        $serverUuid = $request->server_uuid;
        $instantDeploy = $request->instant_deploy ?? false;

        $project = Project::whereTeamId($teamId)->whereUuid($request->project_uuid)->first();
        if (! $project) {
            return response()->json(['succes' => false, 'message' => 'Project not found.'], 404);
        }
        $environment = $project->environments()->where('name', $request->environment_name)->first();
        if (! $environment) {
            return response()->json(['success' => false, 'message' => 'Environment not found.'], 404);
        }
        $server = Server::whereTeamId($teamId)->whereUuid($serverUuid)->first();
        if (! $server) {
            return response()->json(['success' => false, 'message' => 'Server not found.'], 404);
        }
        $destinations = $server->destinations();
        if ($destinations->count() == 0) {
            return response()->json(['success' => false, 'message' => 'Server has no destinations.'], 400);
        }
        if ($destinations->count() > 1 && ! $request->has('destination_uuid')) {
            return response()->json(['success' => false, 'message' => 'Server has multiple destinations and you do not set destination_uuid.'], 400);
        }
        $destination = $destinations->first();

        if ($request->type === NewDatabaseTypes::POSTGRESQL->value) {
            removeUnnecessaryFieldsFromRequest($request);
            $database = create_standalone_postgresql($environment->id, $destination->uuid, $request->all());
            if ($instantDeploy) {
                StartPostgresql::dispatch($database);
            }

            return response()->json([
                'success' => true,
                'message' => 'Database starting queued.',
                'data' => serializeApiResponse($database),
            ]);
        } elseif ($request->type === NewDatabaseTypes::MARIADB->value) {
            removeUnnecessaryFieldsFromRequest($request);
            $database = create_standalone_mariadb($environment->id, $destination->uuid, $request->all());
            if ($instantDeploy) {
                StartMariadb::dispatch($database);
            }

            return response()->json([
                'success' => true,
                'message' => 'Database starting queued.',
                'data' => serializeApiResponse($database),
            ]);
        } elseif ($request->type === NewDatabaseTypes::MYSQL->value) {
            removeUnnecessaryFieldsFromRequest($request);
            $database = create_standalone_mysql($environment->id, $destination->uuid, $request->all());
            if ($instantDeploy) {
                StartMysql::dispatch($database);
            }

            return response()->json([
                'success' => true,
                'message' => 'Database starting queued.',
                'data' => serializeApiResponse($database),
            ]);
        } elseif ($request->type === NewDatabaseTypes::REDIS->value) {
            removeUnnecessaryFieldsFromRequest($request);
            $database = create_standalone_redis($environment->id, $destination->uuid, $request->all());
            if ($instantDeploy) {
                StartRedis::dispatch($database);
            }

            return response()->json([
                'success' => true,
                'message' => 'Database starting queued.',
                'data' => serializeApiResponse($database),
            ]);
        } elseif ($request->type === NewDatabaseTypes::DRAGONFLY->value) {
            removeUnnecessaryFieldsFromRequest($request);
            $database = create_standalone_dragonfly($environment->id, $destination->uuid, $request->all());
            if ($instantDeploy) {
                StartDragonfly::dispatch($database);
            }

            return response()->json([
                'success' => true,
                'message' => 'Database starting queued.',
                'data' => serializeApiResponse($database),
            ]);
        } elseif ($request->type === NewDatabaseTypes::KEYDB->value) {
            removeUnnecessaryFieldsFromRequest($request);
            $database = create_standalone_keydb($environment->id, $destination->uuid, $request->all());
            if ($instantDeploy) {
                StartKeydb::dispatch($database);
            }

            return response()->json([
                'success' => true,
                'message' => 'Database starting queued.',
                'data' => serializeApiResponse($database),
            ]);
        } elseif ($request->type === NewDatabaseTypes::CLICKHOUSE->value) {
            removeUnnecessaryFieldsFromRequest($request);
            $database = create_standalone_clickhouse($environment->id, $destination->uuid, $request->all());
            if ($instantDeploy) {
                StartClickhouse::dispatch($database);
            }

            return response()->json([
                'success' => true,
                'message' => 'Database starting queued.',
                'data' => serializeApiResponse($database),
            ]);
        } elseif ($request->type === NewDatabaseTypes::MONGODB->value) {
            removeUnnecessaryFieldsFromRequest($request);
            $database = create_standalone_mongodb($environment->id, $destination->uuid, $request->all());
            if ($instantDeploy) {
                StartMongodb::dispatch($database);
            }

            return response()->json([
                'success' => true,
                'message' => 'Database starting queued.',
                'data' => serializeApiResponse($database),
            ]);
        }

        return response()->json(['success' => false, 'message' => 'Invalid database type requested.'], 400);
    }

    public function delete_by_uuid(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        if (! $request->uuid) {
            return response()->json(['success' => false, 'message' => 'UUID is required.'], 404);
        }
        $database = queryDatabaseByUuidWithinTeam($request->uuid, $teamId);
        if (! $database) {
            return response()->json(['success' => false, 'message' => 'Database not found.'], 404);
        }
        StopDatabase::dispatch($database);
        $database->delete();

        return response()->json([
            'success' => true,
            'message' => 'Database deletion request queued.',
        ]);
    }
}
