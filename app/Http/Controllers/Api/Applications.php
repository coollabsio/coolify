<?php

namespace App\Http\Controllers\Api;

use App\Actions\Application\StopApplication;
use App\Enums\BuildPackTypes;
use App\Enums\NewResourceTypes;
use App\Http\Controllers\Controller;
use App\Jobs\DeleteResourceJob;
use App\Models\Application;
use App\Models\EnvironmentVariable;
use App\Models\GithubApp;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Visus\Cuid2\Cuid2;

class Applications extends Controller
{
    public function applications(Request $request)
    {
        $teamId = get_team_id_from_token();
        if (is_null($teamId)) {
            return invalid_token();
        }
        $projects = Project::where('team_id', $teamId)->get();
        $applications = collect();
        $applications->push($projects->pluck('applications')->flatten());
        $applications = $applications->flatten();

        return response()->json(serialize_api_response($applications));
    }

    public function create_application(Request $request)
    {

        ray()->clearAll();
        $allowedFields = ['project_uuid', 'environment_name', 'server_uuid', 'destination_uuid', 'type', 'name', 'description', 'is_static', 'domains', 'git_repository', 'git_branch', 'git_commit_sha', 'docker_registry_image_name', 'docker_registry_image_tag', 'build_pack', 'install_command', 'build_command', 'start_command', 'ports_exposes', 'ports_mappings', 'base_directory', 'publish_directory', 'health_check_enabled', 'health_check_path', 'health_check_port', 'health_check_host', 'health_check_method', 'health_check_return_code', 'health_check_scheme', 'health_check_response_text', 'health_check_interval', 'health_check_timeout', 'health_check_retries', 'health_check_start_period', 'limits_memory', 'limits_memory_swap', 'limits_memory_swappiness', 'limits_memory_reservation', 'limits_cpus', 'limits_cpuset', 'limits_cpu_shares', 'custom_labels', 'custom_docker_run_options', 'post_deployment_command', 'post_deployment_command_container', 'pre_deployment_command', 'pre_deployment_command_container',  'manual_webhook_secret_github', 'manual_webhook_secret_gitlab', 'manual_webhook_secret_bitbucket', 'manual_webhook_secret_gitea', 'redirect', 'github_app_uuid', 'instant_deploy', 'dockerfile'];
        $teamId = get_team_id_from_token();
        if (is_null($teamId)) {
            return invalid_token();
        }
        if (! $request->isJson()) {
            return response()->json([
                'message' => 'Invalid request.',
                'error' => 'Content-Type must be application/json.',
            ], 400);
        }
        // check if request is valid json
        if (! json_decode($request->getContent())) {
            return response()->json([
                'message' => 'Invalid request.',
                'error' => 'Invalid JSON.',
            ], 400);
        }
        $validator = customApiValidator($request->all(), [
            'name' => 'string|max:255',
            'description' => 'string|nullable',
            'project_uuid' => 'string|required',
            'environment_name' => 'string|required',
            'server_uuid' => 'string|required',
            'destination_uuid' => 'string',
            'type' => ['required', Rule::enum(NewResourceTypes::class)],
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
        $fqdn = $request->domains;
        $type = $request->type;
        $instantDeploy = $request->instant_deploy;
        $githubAppUuid = $request->github_app_uuid;

        $project = Project::whereTeamId($teamId)->whereUuid($request->project_uuid)->first();
        if (! $project) {
            return response()->json(['error' => 'Project not found.'], 404);
        }
        $environment = $project->environments()->where('name', $request->environment_name)->first();
        if (! $environment) {
            return response()->json(['error' => 'Environment not found.'], 404);
        }
        $server = Server::whereTeamId($teamId)->whereUuid($serverUuid)->first();
        if (! $server) {
            return response()->json(['error' => 'Server not found.'], 404);
        }
        $destinations = $server->destinations();
        if ($destinations->count() == 0) {
            return response()->json(['error' => 'Server has no destinations.'], 400);
        }
        if ($destinations->count() > 1 && ! $request->has('destination_uuid')) {
            return response()->json(['error' => 'Server has multiple destinations and you do not set destination_uuid.'], 400);
        }
        $destination = $destinations->first();
        if ($type === 'public') {
            if (! $request->has('name')) {
                $request->offsetSet('name', generate_application_name($request->git_repository, $request->git_branch));
            }
            $validator = customApiValidator($request->all(), [
                sharedDataApplications(),
                'git_repository' => 'string|required',
                'git_branch' => 'string|required',
                'build_pack' => ['required', Rule::enum(BuildPackTypes::class)],
                'ports_exposes' => 'string|regex:/^(\d+)(,\d+)*$/|required',
            ]);
            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => $validator->errors(),
                ], 422);
            }
            $return = $this->validateDataApplications($request, $server);
            if ($return instanceof \Illuminate\Http\JsonResponse) {
                return $return;
            }
            $application = new Application();
            $this->removeUnnecessaryFieldsFromRequest($request);

            $application->fill($request->all());

            $application->fqdn = $fqdn;
            $application->destination_id = $destination->id;
            $application->destination_type = $destination->getMorphClass();
            $application->environment_id = $environment->id;
            $application->save();

            if ($instantDeploy) {
                $deployment_uuid = new Cuid2(7);

                queue_application_deployment(
                    application: $application,
                    deployment_uuid: $deployment_uuid,
                    no_questions_asked: true,
                    is_api: true,
                );
            }

            return response()->json(serialize_api_response($application));
        } elseif ($type === 'private-gh-app') {
            if (! $request->has('name')) {
                $request->offsetSet('name', generate_application_name($request->git_repository, $request->git_branch));
            }
            $validator = customApiValidator($request->all(), [
                sharedDataApplications(),
                'git_repository' => 'string|required',
                'git_branch' => 'string|required',
                'build_pack' => ['required', Rule::enum(BuildPackTypes::class)],
                'ports_exposes' => 'string|regex:/^(\d+)(,\d+)*$/|required',
                'github_app_uuid' => 'string|required',
            ]);
            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => $validator->errors(),
                ], 422);
            }
            $return = $this->validateDataApplications($request, $server);
            if ($return instanceof \Illuminate\Http\JsonResponse) {
                return $return;
            }
            $githubApp = GithubApp::whereTeamId($teamId)->where('uuid', $githubAppUuid)->first();
            if (! $githubApp) {
                return response()->json(['error' => 'Github App not found.'], 404);
            }
            $gitRepository = $request->git_repository;
            if (str($gitRepository)->startsWith('http') || str($gitRepository)->contains('github.com')) {
                $gitRepository = str($gitRepository)->replace('https://', '')->replace('http://', '')->replace('github.com/', '');
            }
            $application = new Application();
            $this->removeUnnecessaryFieldsFromRequest($request);

            $application->fill($request->all());

            $application->fqdn = $fqdn;
            $application->git_repository = $gitRepository;
            $application->destination_id = $destination->id;
            $application->destination_type = $destination->getMorphClass();
            $application->environment_id = $environment->id;
            $application->source_type = $githubApp->getMorphClass();
            $application->source_id = $githubApp->id;
            $application->save();

            if ($instantDeploy) {
                $deployment_uuid = new Cuid2(7);

                queue_application_deployment(
                    application: $application,
                    deployment_uuid: $deployment_uuid,
                    no_questions_asked: true,
                    is_api: true,
                );
            }

            return response()->json(serialize_api_response($application));
        } elseif ($type === 'private-deploy-key') {
            if (! $request->has('name')) {
                $request->offsetSet('name', generate_application_name($request->git_repository, $request->git_branch));
            }
            $validator = customApiValidator($request->all(), [
                sharedDataApplications(),
                'git_repository' => 'string|required',
                'git_branch' => 'string|required',
                'build_pack' => ['required', Rule::enum(BuildPackTypes::class)],
                'ports_exposes' => 'string|regex:/^(\d+)(,\d+)*$/|required',
                'private_key_uuid' => 'string|required',
            ]);
            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => $validator->errors(),
                ], 422);
            }
            $return = $this->validateDataApplications($request, $server);
            if ($return instanceof \Illuminate\Http\JsonResponse) {
                return $return;
            }
            $privateKey = PrivateKey::whereTeamId($teamId)->where('uuid', $request->private_key_uuid)->first();
            if (! $privateKey) {
                return response()->json(['error' => 'Private Key not found.'], 404);
            }

            $application = new Application();
            $this->removeUnnecessaryFieldsFromRequest($request);

            $application->fill($request->all());
            $application->fqdn = $fqdn;
            $application->private_key_id = $privateKey->id;
            $application->destination_id = $destination->id;
            $application->destination_type = $destination->getMorphClass();
            $application->environment_id = $environment->id;
            $application->save();

            if ($instantDeploy) {
                $deployment_uuid = new Cuid2(7);

                queue_application_deployment(
                    application: $application,
                    deployment_uuid: $deployment_uuid,
                    no_questions_asked: true,
                    is_api: true,
                );
            }

            return response()->json(serialize_api_response($application));
        } elseif ($type === 'dockerfile') {
            if (! $request->has('name')) {
                $request->offsetSet('name', 'dockerfile-'.new Cuid2(7));
            }
            $validator = customApiValidator($request->all(), [
                sharedDataApplications(),
                'dockerfile' => 'string|required',
            ]);
            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => $validator->errors(),
                ], 422);
            }
            $return = $this->validateDataApplications($request, $server);
            if ($return instanceof \Illuminate\Http\JsonResponse) {
                return $return;
            }
            if (! isBase64Encoded($request->dockerfile)) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => [
                        'dockerfile' => 'The dockerfile should be base64 encoded.',
                    ],
                ], 422);
            }
            $dockerFile = base64_decode($request->dockerfile);
            if (mb_detect_encoding($dockerFile, 'ASCII', true) === false) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => [
                        'dockerfile' => 'The dockerfile should be base64 encoded.',
                    ],
                ], 422);
            }
            $dockerFile = base64_decode($request->dockerfile);
            $this->removeUnnecessaryFieldsFromRequest($request);

            $port = get_port_from_dockerfile($request->dockerfile);
            if (! $port) {
                $port = 80;
            }

            $application = new Application();
            $application->fill($request->all());
            $application->fqdn = $fqdn;
            $application->ports_exposes = $port;
            $application->build_pack = 'dockerfile';
            $application->dockerfile = $dockerFile;
            $application->destination_id = $destination->id;
            $application->destination_type = $destination->getMorphClass();
            $application->environment_id = $environment->id;

            $application->git_repository = 'coollabsio/coolify';
            $application->git_branch = 'main';
            $application->save();

            if ($instantDeploy) {
                $deployment_uuid = new Cuid2(7);

                queue_application_deployment(
                    application: $application,
                    deployment_uuid: $deployment_uuid,
                    no_questions_asked: true,
                    is_api: true,
                );
            }

            return response()->json(serialize_api_response($application));
        }

        return response()->json(['error' => 'Invalid type.'], 400);

    }

    public function application_by_uuid(Request $request)
    {
        $teamId = get_team_id_from_token();
        if (is_null($teamId)) {
            return invalid_token();
        }
        $uuid = $request->route('uuid');
        if (! $uuid) {
            return response()->json(['error' => 'UUID is required.'], 400);
        }
        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $request->uuid)->first();
        if (! $application) {
            return response()->json(['error' => 'Application not found.'], 404);
        }

        return response()->json(serialize_api_response($application));
    }

    public function delete_by_uuid(Request $request)
    {
        ray()->clearAll();
        $teamId = get_team_id_from_token();
        $cleanup = $request->query->get('cleanup') ?? false;
        if (is_null($teamId)) {
            return invalid_token();
        }

        if ($request->collect()->count() == 0) {
            return response()->json([
                'message' => 'Invalid request.',
            ], 400);
        }
        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $request->uuid)->first();

        if (! $application) {
            return response()->json([
                'success' => false,
                'message' => 'Application not found',
            ], 404);
        }
        DeleteResourceJob::dispatch($application, $cleanup);

        return response()->json([
            'success' => true,
            'message' => 'Application deletion request queued.',
        ]);
    }

    public function update_by_uuid(Request $request)
    {
        ray()->clearAll();
        $teamId = get_team_id_from_token();
        if (is_null($teamId)) {
            return invalid_token();
        }

        if ($request->collect()->count() == 0) {
            return response()->json([
                'message' => 'Invalid request.',
            ], 400);
        }
        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $request->uuid)->first();

        if (! $application) {
            return response()->json([
                'success' => false,
                'message' => 'Application not found',
            ], 404);
        }
        $server = $application->destination->server;
        $allowedFields = ['name', 'description', 'is_static', 'domains', 'git_repository', 'git_branch', 'git_commit_sha', 'docker_registry_image_name', 'docker_registry_image_tag', 'build_pack', 'static_image', 'install_command', 'build_command', 'start_command', 'ports_exposes', 'ports_mappings', 'base_directory', 'publish_directory', 'health_check_enabled', 'health_check_path', 'health_check_port', 'health_check_host', 'health_check_method', 'health_check_return_code', 'health_check_scheme', 'health_check_response_text', 'health_check_interval', 'health_check_timeout', 'health_check_retries', 'health_check_start_period', 'limits_memory', 'limits_memory_swap', 'limits_memory_swappiness', 'limits_memory_reservation', 'limits_cpus', 'limits_cpuset', 'limits_cpu_shares', 'custom_labels', 'custom_docker_run_options', 'post_deployment_command', 'post_deployment_command_container', 'pre_deployment_command', 'pre_deployment_command_container', 'watch_paths', 'manual_webhook_secret_github', 'manual_webhook_secret_gitlab', 'manual_webhook_secret_bitbucket', 'manual_webhook_secret_gitea', 'docker_compose_location', 'docker_compose', 'docker_compose_raw', 'docker_compose_custom_start_command', 'docker_compose_custom_build_command', 'redirect'];

        $validator = customApiValidator($request->all(), [
            sharedDataApplications(),
            'name' => 'string|max:255',
            'description' => 'string|nullable',
            'static_image' => 'string',
            'watch_paths' => 'string|nullable',
            'docker_compose_location' => 'string',
            'docker_compose' => 'string|nullable',
            'docker_compose_raw' => 'string|nullable',
            // 'docker_compose_domains' => 'string|nullable', // must be like: "{\"api\":{\"domain\":\"http:\\/\\/b8sos8k.127.0.0.1.sslip.io\"}}"
            'docker_compose_custom_start_command' => 'string|nullable',
            'docker_compose_custom_build_command' => 'string|nullable',
        ]);

        // Validate ports_exposes
        if ($request->has('ports_exposes')) {
            $ports = explode(',', $request->ports_exposes);
            foreach ($ports as $port) {
                if (! is_numeric($port)) {
                    return response()->json([
                        'message' => 'Validation failed.',
                        'errors' => [
                            'ports_exposes' => 'The ports_exposes should be a comma separated list of numbers.',
                        ],
                    ], 422);
                }
            }
        }
        $return = $this->validateDataApplications($request, $server);
        if ($return instanceof \Illuminate\Http\JsonResponse) {
            return $return;
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
        $domains = $request->domains;
        if ($request->has('domains') && $server->isProxyShouldRun()) {
            $fqdn = $request->domains;
            $fqdn = str($fqdn)->replaceEnd(',', '')->trim();
            $fqdn = str($fqdn)->replaceStart(',', '')->trim();
            $errors = [];
            $fqdn = $fqdn->unique()->implode(',');
            $application->fqdn = $fqdn;
            $customLabels = str(implode('|coolify|', generateLabelsApplication($application)))->replace('|coolify|', "\n");
            $application->custom_labels = base64_encode($customLabels);
            $request->offsetUnset('domains');
        }

        $data = $request->all();
        data_set($data, 'fqdn', $domains);
        $application->fill($data);
        $application->save();

        return response()->json(serialize_api_response($application));
    }

    public function envs_by_uuid(Request $request)
    {
        ray()->clearAll();
        $teamId = get_team_id_from_token();
        if (is_null($teamId)) {
            return invalid_token();
        }
        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $request->uuid)->first();

        if (! $application) {
            return response()->json([
                'success' => false,
                'message' => 'Application not found',
            ], 404);
        }
        $envs = $application->environment_variables->sortBy('id')->merge($application->environment_variables_preview->sortBy('id'));

        return response()->json(serialize_api_response($envs));
    }

    public function update_env_by_uuid(Request $request)
    {
        ray()->clearAll();
        $allowedFields = ['key', 'value', 'is_preview', 'is_build_time', 'is_literal'];
        $teamId = get_team_id_from_token();

        if (is_null($teamId)) {
            return invalid_token();
        }
        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $request->uuid)->first();

        if (! $application) {
            return response()->json([
                'success' => false,
                'message' => 'Application not found',
            ], 404);
        }
        $validator = customApiValidator($request->all(), [
            'key' => 'string|required',
            'value' => 'string|nullable',
            'is_preview' => 'boolean',
            'is_build_time' => 'boolean',
            'is_literal' => 'boolean',
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
        $is_preview = $request->is_preview ?? false;
        $is_build_time = $request->is_build_time ?? false;
        $is_literal = $request->is_literal ?? false;
        if ($is_preview) {
            $env = $application->environment_variables_preview->where('key', $request->key)->first();
            if ($env) {
                $env->value = $request->value;
                if ($env->is_build_time != $is_build_time) {
                    $env->is_build_time = $is_build_time;
                }
                if ($env->is_literal != $is_literal) {
                    $env->is_literal = $is_literal;
                }
                if ($env->is_preview != $is_preview) {
                    $env->is_preview = $is_preview;
                }
                $env->save();

                return response()->json(serialize_api_response($env));
            } else {
                return response()->json([
                    'message' => 'Environment variable not found.',
                ], 404);
            }
        } else {
            $env = $application->environment_variables->where('key', $request->key)->first();
            if ($env) {
                $env->value = $request->value;
                if ($env->is_build_time != $is_build_time) {
                    $env->is_build_time = $is_build_time;
                }
                if ($env->is_literal != $is_literal) {
                    $env->is_literal = $is_literal;
                }
                if ($env->is_preview != $is_preview) {
                    $env->is_preview = $is_preview;
                }
                $env->save();

                return response()->json(serialize_api_response($env));
            } else {

                return response()->json([
                    'message' => 'Environment variable not found.',
                ], 404);

            }
        }

        return response()->json([
            'message' => 'Something went wrong.',
        ], 500);

    }

    public function create_bulk_envs(Request $request)
    {
        ray()->clearAll();
        $teamId = get_team_id_from_token();

        if (is_null($teamId)) {
            return invalid_token();
        }
        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $request->uuid)->first();

        if (! $application) {
            return response()->json([
                'success' => false,
                'message' => 'Application not found',
            ], 404);
        }

        $bulk_data = $request->get('data');
        if (! $bulk_data) {
            return response()->json([
                'message' => 'Bulk data is required.',
            ], 400);
        }
        $bulk_data = collect($bulk_data)->map(function ($item) {
            return collect($item)->only(['key', 'value', 'is_preview', 'is_build_time', 'is_literal']);
        });
        foreach ($bulk_data as $item) {
            $validator = customApiValidator($item, [
                'key' => 'string|required',
                'value' => 'string|nullable',
                'is_preview' => 'boolean',
                'is_build_time' => 'boolean',
                'is_literal' => 'boolean',
            ]);
            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => $validator->errors(),
                ], 422);
            }
            $is_preview = $item->get('is_preview') ?? false;
            $is_build_time = $item->get('is_build_time') ?? false;
            $is_literal = $item->get('is_literal') ?? false;
            if ($is_preview) {
                $env = $application->environment_variables_preview->where('key', $item->get('key'))->first();
                if ($env) {
                    $env->value = $item->get('value');
                    if ($env->is_build_time != $is_build_time) {
                        $env->is_build_time = $is_build_time;
                    }
                    if ($env->is_literal != $is_literal) {
                        $env->is_literal = $is_literal;
                    }
                    $env->save();
                } else {
                    $env = $application->environment_variables()->create([
                        'key' => $item->get('key'),
                        'value' => $item->get('value'),
                        'is_preview' => $is_preview,
                        'is_build_time' => $is_build_time,
                        'is_literal' => $is_literal,
                    ]);
                }
            } else {
                $env = $application->environment_variables->where('key', $item->get('key'))->first();
                if ($env) {
                    $env->value = $item->get('value');
                    if ($env->is_build_time != $is_build_time) {
                        $env->is_build_time = $is_build_time;
                    }
                    if ($env->is_literal != $is_literal) {
                        $env->is_literal = $is_literal;
                    }
                    $env->save();
                } else {
                    $env = $application->environment_variables()->create([
                        'key' => $item->get('key'),
                        'value' => $item->get('value'),
                        'is_preview' => $is_preview,
                        'is_build_time' => $is_build_time,
                        'is_literal' => $is_literal,
                    ]);
                }
            }
        }

        return response()->json([
            'message' => 'Environments updated.',
        ]);
    }

    public function create_env(Request $request)
    {
        ray()->clearAll();
        $allowedFields = ['key', 'value', 'is_preview', 'is_build_time', 'is_literal'];
        $teamId = get_team_id_from_token();

        if (is_null($teamId)) {
            return invalid_token();
        }
        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $request->uuid)->first();

        if (! $application) {
            return response()->json([
                'success' => false,
                'message' => 'Application not found',
            ], 404);
        }
        $validator = customApiValidator($request->all(), [
            'key' => 'string|required',
            'value' => 'string|nullable',
            'is_preview' => 'boolean',
            'is_build_time' => 'boolean',
            'is_literal' => 'boolean',
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
        $is_preview = $request->is_preview ?? false;
        if ($is_preview) {
            $env = $application->environment_variables_preview->where('key', $request->key)->first();
            if ($env) {
                return response()->json([
                    'message' => 'Environment variable already exists. Use PATCH request to update it.',
                ], 409);
            } else {
                $env = $application->environment_variables()->create([
                    'key' => $request->key,
                    'value' => $request->value,
                    'is_preview' => $request->is_preview ?? false,
                    'is_build_time' => $request->is_build_time ?? false,
                    'is_literal' => $request->is_literal ?? false,
                ]);

                return response()->json(serialize_api_response($env))->setStatusCode(201);
            }
        } else {
            $env = $application->environment_variables->where('key', $request->key)->first();
            if ($env) {
                return response()->json([
                    'message' => 'Environment variable already exists. Use PATCH request to update it.',
                ], 409);
            } else {
                $env = $application->environment_variables()->create([
                    'key' => $request->key,
                    'value' => $request->value,
                    'is_preview' => $request->is_preview ?? false,
                    'is_build_time' => $request->is_build_time ?? false,
                    'is_literal' => $request->is_literal ?? false,
                ]);

                return response()->json(serialize_api_response($env))->setStatusCode(201);

            }
        }

        return response()->json([
            'message' => 'Something went wrong.',
        ], 500);

    }

    public function delete_env_by_uuid(Request $request)
    {
        ray()->clearAll();
        $teamId = get_team_id_from_token();
        if (is_null($teamId)) {
            return invalid_token();
        }
        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $request->uuid)->first();

        if (! $application) {
            return response()->json([
                'success' => false,
                'message' => 'Application not found.',
            ], 404);
        }
        $found_env = EnvironmentVariable::where('uuid', $request->env_uuid)->where('application_id', $application->id)->first();
        if (! $found_env) {
            return response()->json([
                'success' => false,
                'message' => 'Environment variable not found.',
            ], 404);
        }
        $found_env->delete();

        return response()->json([
            'success' => true,
            'message' => 'Environment variable deleted.',
        ]);
    }

    public function action_deploy(Request $request)
    {
        $teamId = get_team_id_from_token();
        if (is_null($teamId)) {
            return invalid_token();
        }
        $force = $request->query->get('force') ?? false;
        $instant_deploy = $request->query->get('instant_deploy') ?? false;
        $uuid = $request->route('uuid');
        if (! $uuid) {
            return response()->json(['error' => 'UUID is required.'], 400);
        }
        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $request->uuid)->first();
        if (! $application) {
            return response()->json(['error' => 'Application not found.'], 404);
        }

        $deployment_uuid = new Cuid2(7);

        queue_application_deployment(
            application: $application,
            deployment_uuid: $deployment_uuid,
            force_rebuild: $force,
            is_api: true,
            no_questions_asked: $instant_deploy
        );

        return response()->json(
            [
                'message' => 'Deployment request queued.',
                'deployment_uuid' => $deployment_uuid->toString(),
                'deployment_api_url' => base_url().'/api/v1/deployment/'.$deployment_uuid->toString(),
            ],
            200
        );
    }

    public function action_stop(Request $request)
    {
        $teamId = get_team_id_from_token();
        if (is_null($teamId)) {
            return invalid_token();
        }
        $uuid = $request->route('uuid');
        $sync = $request->query->get('sync') ?? false;
        if (! $uuid) {
            return response()->json(['error' => 'UUID is required.'], 400);
        }
        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $request->uuid)->first();
        if (! $application) {
            return response()->json(['error' => 'Application not found.'], 404);
        }
        if ($sync) {
            StopApplication::run($application);

            return response()->json(['message' => 'Stopped the application.'], 200);
        } else {
            StopApplication::dispatch($application);

            return response()->json(['message' => 'Stopping request queued.'], 200);
        }
    }

    public function action_restart(Request $request)
    {
        $teamId = get_team_id_from_token();
        if (is_null($teamId)) {
            return invalid_token();
        }
        $uuid = $request->route('uuid');
        if (! $uuid) {
            return response()->json(['error' => 'UUID is required.'], 400);
        }
        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $request->uuid)->first();
        if (! $application) {
            return response()->json(['error' => 'Application not found.'], 404);
        }

        $deployment_uuid = new Cuid2(7);

        queue_application_deployment(
            application: $application,
            deployment_uuid: $deployment_uuid,
            restart_only: true,
            is_api: true,
        );

        return response()->json(
            [
                'message' => 'Restart request queued.',
                'deployment_uuid' => $deployment_uuid->toString(),
                'deployment_api_url' => base_url().'/api/v1/deployment/'.$deployment_uuid->toString(),
            ],
            200
        );

    }

    private function removeUnnecessaryFieldsFromRequest(Request $request)
    {
        $request->offsetUnset('project_uuid');
        $request->offsetUnset('environment_name');
        $request->offsetUnset('destination_uuid');
        $request->offsetUnset('server_uuid');
        $request->offsetUnset('type');
        $request->offsetUnset('domains');
        $request->offsetUnset('instant_deploy');
        $request->offsetUnset('github_app_uuid');
        $request->offsetUnset('private_key_uuid');
    }

    private function validateDataApplications(Request $request, Server $server)
    {
        $teamId = get_team_id_from_token();

        // Validate ports_mappings
        if ($request->has('ports_mappings')) {
            $ports = [];
            foreach (explode(',', $request->ports_mappings) as $portMapping) {
                $port = explode(':', $portMapping);
                if (in_array($port[0], $ports)) {
                    return response()->json([
                        'message' => 'Validation failed.',
                        'errors' => [
                            'ports_mappings' => 'The first number before : should be unique between mappings.',
                        ],
                    ], 422);
                }
                $ports[] = $port[0];
            }
        }
        // Validate custom_labels
        if ($request->has('custom_labels')) {
            if (! isBase64Encoded($request->custom_labels)) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => [
                        'custom_labels' => 'The custom_labels should be base64 encoded.',
                    ],
                ], 422);
            }
            $customLabels = base64_decode($request->custom_labels);
            if (mb_detect_encoding($customLabels, 'ASCII', true) === false) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => [
                        'custom_labels' => 'The custom_labels should be base64 encoded.',
                    ],
                ], 422);

            }
        }
        if ($request->has('domains') && $server->isProxyShouldRun()) {
            $fqdn = $request->domains;
            $fqdn = str($fqdn)->replaceEnd(',', '')->trim();
            $fqdn = str($fqdn)->replaceStart(',', '')->trim();
            $errors = [];
            $fqdn = str($fqdn)->trim()->explode(',')->map(function ($domain) use (&$errors) {
                if (filter_var($domain, FILTER_VALIDATE_URL) === false) {
                    $errors[] = 'Invalid domain: '.$domain;
                }

                return str($domain)->trim()->lower();
            });
            if (count($errors) > 0) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => $errors,
                ], 422);
            }
            if (checkIfDomainIsAlreadyUsed($fqdn, $teamId)) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => [
                        'domains' => 'One of the domain is already used.',
                    ],
                ], 422);
            }
        }
    }
}
