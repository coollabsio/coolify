<?php

namespace App\Http\Controllers\Api;

use App\Actions\Service\RestartService;
use App\Actions\Service\StartService;
use App\Actions\Service\StopService;
use App\Http\Controllers\Controller;
use App\Jobs\DeleteResourceJob;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Http\Request;

class ServicesController extends Controller
{
    private function removeSensitiveData($service)
    {
        $token = auth()->user()->currentAccessToken();
        $service->makeHidden([
            'id',
        ]);
        if ($token->can('view:sensitive')) {
            return serializeApiResponse($service);
        }

        $service->makeHidden([
            'docker_compose_raw',
            'docker_compose',
        ]);

        return serializeApiResponse($service);
    }

    public function services(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $projects = Project::where('team_id', $teamId)->get();
        $services = collect();
        foreach ($projects as $project) {
            $services->push($project->services()->get());
        }
        foreach ($services as $service) {
            $service = $this->removeSensitiveData($service);
        }

        return response()->json($services->flatten());
    }

    public function create_service(Request $request)
    {
        $allowedFields = ['type', 'name', 'description', 'project_uuid', 'environment_name', 'server_uuid', 'destination_uuid', 'instant_deploy'];

        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $return = validateIncomingRequest($request);
        if ($return instanceof \Illuminate\Http\JsonResponse) {
            return $return;
        }
        $validator = customApiValidator($request->all(), [
            'type' => 'string|required',
            'project_uuid' => 'string|required',
            'environment_name' => 'string|required',
            'server_uuid' => 'string|required',
            'destination_uuid' => 'string',
            'name' => 'string|max:255',
            'description' => 'string|nullable',
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
        $services = get_service_templates();
        $serviceKeys = $services->keys();
        if ($serviceKeys->contains($request->type)) {
            $oneClickServiceName = $request->type;
            $oneClickService = data_get($services, "$oneClickServiceName.compose");
            $oneClickDotEnvs = data_get($services, "$oneClickServiceName.envs", null);
            if ($oneClickDotEnvs) {
                $oneClickDotEnvs = str(base64_decode($oneClickDotEnvs))->split('/\r\n|\r|\n/')->filter(function ($value) {
                    return ! empty($value);
                });
            }
            if ($oneClickService) {
                $service_payload = [
                    'name' => "$oneClickServiceName-".str()->random(10),
                    'docker_compose_raw' => base64_decode($oneClickService),
                    'environment_id' => $environment->id,
                    'service_type' => $oneClickServiceName,
                    'server_id' => $server->id,
                    'destination_id' => $destination->id,
                    'destination_type' => $destination->getMorphClass(),
                ];
                if ($oneClickServiceName === 'cloudflared') {
                    data_set($service_payload, 'connect_to_docker_network', true);
                }
                $service = Service::create($service_payload);
                $service->name = "$oneClickServiceName-".$service->uuid;
                $service->save();
                if ($oneClickDotEnvs?->count() > 0) {
                    $oneClickDotEnvs->each(function ($value) use ($service) {
                        $key = str()->before($value, '=');
                        $value = str(str()->after($value, '='));
                        $generatedValue = $value;
                        if ($value->contains('SERVICE_')) {
                            $command = $value->after('SERVICE_')->beforeLast('_');
                            $generatedValue = generateEnvValue($command->value(), $service);
                        }
                        EnvironmentVariable::create([
                            'key' => $key,
                            'value' => $generatedValue,
                            'service_id' => $service->id,
                            'is_build_time' => false,
                            'is_preview' => false,
                        ]);
                    });
                }
                $service->parse(isNew: true);
                if ($instantDeploy) {
                    StartService::dispatch($service);
                }
                $domains = $service->applications()->get()->pluck('fqdn')->sort();
                $domains = $domains->map(function ($domain) {
                    return str($domain)->beforeLast(':')->value();
                });

                return response()->json([
                    'uuid' => $service->uuid,
                    'domains' => $domains,
                ]);
            }

            return response()->json(['message' => 'Service not found.'], 404);
        } else {
            return response()->json(['message' => 'Invalid service type.', 'valid_service_types' => $serviceKeys], 400);
        }

        return response()->json(['message' => 'Invalid service type.'], 400);
    }

    public function service_by_uuid(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        if (! $request->uuid) {
            return response()->json(['message' => 'UUID is required.'], 404);
        }
        $service = Service::whereRelation('environment.project.team', 'id', $teamId)->whereUuid($request->uuid)->first();
        if (! $service) {
            return response()->json(['message' => 'Service not found.'], 404);
        }

        return response()->json($this->removeSensitiveData($service));
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
        $service = Service::whereRelation('environment.project.team', 'id', $teamId)->whereUuid($request->uuid)->first();
        if (! $service) {
            return response()->json(['message' => 'Service not found.'], 404);
        }
        DeleteResourceJob::dispatch($service);

        return response()->json([
            'message' => 'Service deletion request queued.',
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
        $service = Service::whereRelation('environment.project.team', 'id', $teamId)->whereUuid($request->uuid)->first();
        if (! $service) {
            return response()->json(['message' => 'Service not found.'], 404);
        }
        if (str($service->status())->contains('running')) {
            return response()->json(['message' => 'Service is already running.'], 400);
        }
        StartService::dispatch($service);

        return response()->json(
            [
                'message' => 'Service starting request queued.',
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
        $service = Service::whereRelation('environment.project.team', 'id', $teamId)->whereUuid($request->uuid)->first();
        if (! $service) {
            return response()->json(['message' => 'Service not found.'], 404);
        }
        if (str($service->status())->contains('stopped') || str($service->status())->contains('exited')) {
            return response()->json(['message' => 'Service is already stopped.'], 400);
        }
        StopService::dispatch($service);

        return response()->json(
            [
                'message' => 'Service stopping request queued.',
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
        $service = Service::whereRelation('environment.project.team', 'id', $teamId)->whereUuid($request->uuid)->first();
        if (! $service) {
            return response()->json(['message' => 'Service not found.'], 404);
        }
        RestartService::dispatch($service);

        return response()->json(
            [
                'message' => 'Service restarting request queued.',
            ],
            200
        );

    }
}
