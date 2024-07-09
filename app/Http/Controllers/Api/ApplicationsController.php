<?php

namespace App\Http\Controllers\Api;

use App\Actions\Application\LoadComposeFile;
use App\Actions\Application\StopApplication;
use App\Actions\Service\StartService;
use App\Enums\BuildPackTypes;
use App\Http\Controllers\Controller;
use App\Jobs\DeleteResourceJob;
use App\Models\Application;
use App\Models\EnvironmentVariable;
use App\Models\GithubApp;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;
use Symfony\Component\Yaml\Yaml;
use Visus\Cuid2\Cuid2;

class ApplicationsController extends Controller
{
    private function removeSensitiveData($application)
    {
        $token = auth()->user()->currentAccessToken();
        $application->makeHidden([
            'id',
        ]);
        if ($token->can('view:sensitive')) {
            return serializeApiResponse($application);
        }
        $application->makeHidden([
            'custom_labels',
            'dockerfile',
            'docker_compose',
            'docker_compose_raw',
            'manual_webhook_secret_bitbucket',
            'manual_webhook_secret_gitea',
            'manual_webhook_secret_github',
            'manual_webhook_secret_gitlab',
            'private_key_id',
            'value',
            'real_value',
        ]);

        return serializeApiResponse($application);
    }

    #[OA\Get(
        summary: 'List',
        description: 'List all applications.',
        path: '/applications',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Applications'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Get all applications.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/Application')
                        )
                    ),
                ]),
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
    public function applications(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $projects = Project::where('team_id', $teamId)->get();
        $applications = collect();
        $applications->push($projects->pluck('applications')->flatten());
        $applications = $applications->flatten();
        $applications = $applications->map(function ($application) {
            return $this->removeSensitiveData($application);
        });

        return response()->json($applications);
    }

    public function create_public_application(Request $request)
    {
        $this->create_application($request, 'public');
    }

    public function create_private_gh_app_application(Request $request)
    {
        $this->create_application($request, 'private-gh-app');
    }

    public function create_private_deploy_key_application(Request $request)
    {
        $this->create_application($request, 'private-deploy-key');
    }

    public function create_dockerfile_application(Request $request)
    {
        $this->create_application($request, 'dockerfile');
    }

    public function create_dockerimage_application(Request $request)
    {
        $this->create_application($request, 'docker-image');
    }

    public function create_dockercompose_application(Request $request)
    {
        $this->create_application($request, 'dockercompose');
    }

    #[OA\Post(
        summary: 'Create',
        description: 'Create new application.',
        path: '/applications',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Applications'],
        requestBody: new OA\RequestBody(
            description: 'Application object that needs to be created.',
            required: true,
            content: [
                new OA\MediaType(
                    mediaType: 'application/json',
                    schema: new OA\Schema(
                        type: 'object',
                        required: ['type', 'project_uuid', 'server_uuid', 'environment_name'],
                        properties: [
                            'type' => ['type' => 'string', 'enum' => ['public', 'private-gh-app', 'private-deploy-key', 'dockerfile', 'docker-image', 'dockercompose']],
                            'project_uuid' => ['type' => 'string'],
                            'server_uuid' => ['type' => 'string'],
                            'environment_name' => ['type' => 'string'],
                            'destination_uuid' => ['type' => 'string'],
                            'name' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'is_static' => ['type' => 'boolean'],
                            'domains' => ['type' => 'string'],
                            'git_repository' => ['type' => 'string'],
                            'git_branch' => ['type' => 'string'],
                            'git_commit_sha' => ['type' => 'string'],
                            'docker_registry_image_name' => ['type' => 'string'],
                            'docker_registry_image_tag' => ['type' => 'string'],
                            'build_pack' => ['type' => 'string'],
                            'install_command' => ['type' => 'string'],
                            'build_command' => ['type' => 'string'],
                            'start_command' => ['type' => 'string'],
                            'ports_exposes' => ['type' => 'string'],
                            'ports_mappings' => ['type' => 'string'],
                            'base_directory' => ['type' => 'string'],
                            'publish_directory' => ['type' => 'string'],
                            'health_check_enabled' => ['type' => 'boolean'],
                            'health_check_path' => ['type' => 'string'],
                            'health_check_port' => ['type' => 'integer'],
                            'health_check_host' => ['type' => 'string'],
                            'health_check_method' => ['type' => 'string'],
                            'health_check_return_code' => ['type' => 'integer'],
                            'health_check_scheme' => ['type' => 'string'],
                            'health_check_response_text' => ['type' => 'string'],
                            'health_check_interval' => ['type' => 'integer'],
                            'health_check_timeout' => ['type' => 'integer'],
                            'health_check_retries' => ['type' => 'integer'],
                            'health_check_start_period' => ['type' => 'integer'],
                            'limits_memory' => ['type' => 'string'],
                            'limits_memory_swap' => ['type' => 'string'],
                            'limits_memory_swappiness' => ['type' => 'integer'],
                            'limits_memory_reservation' => ['type' => 'string'],
                            'limits_cpus' => ['type' => 'string'],
                            'limits_cpuset' => ['type' => 'string'],
                            'limits_cpu_shares' => ['type' => 'string'],
                            'custom_labels' => ['type' => 'string'],
                            'custom_docker_run_options' => ['type' => 'string'],
                            'post_deployment_command' => ['type' => 'string'],
                            'post_deployment_command_container' => ['type' => 'string'],
                            'pre_deployment_command' => ['type' => 'string'],
                            'pre_deployment_command_container' => ['type' => 'string'],
                            'manual_webhook_secret_github' => ['type' => 'string'],
                            'manual_webhook_secret_gitlab' => ['type' => 'string'],
                            'manual_webhook_secret_bitbucket' => ['type' => 'string'],
                            'manual_webhook_secret_gitea' => ['type' => 'string'],
                            'redirect' => ['type' => 'string'],
                            'github_app_uuid' => ['type' => 'string'],
                            'instant_deploy' => ['type' => 'boolean'],
                            'dockerfile' => ['type' => 'string'],
                            'docker_compose_location' => ['type' => 'string'],
                            'docker_compose_raw' => ['type' => 'string'],
                            'docker_compose_custom_start_command' => ['type' => 'string'],
                            'docker_compose_custom_build_command' => ['type' => 'string'],
                            'docker_compose_domains' => ['type' => 'array'],
                            'watch_paths' => ['type' => 'string'],
                        ],
                    )),
            ]),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Get all applications.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/Application')
                        )
                    ),
                ]),
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
    private function create_application(Request $request, $type)
    {
        $allowedFields = ['project_uuid', 'environment_name', 'server_uuid', 'destination_uuid', 'type', 'name', 'description', 'is_static', 'domains', 'git_repository', 'git_branch', 'git_commit_sha', 'docker_registry_image_name', 'docker_registry_image_tag', 'build_pack', 'install_command', 'build_command', 'start_command', 'ports_exposes', 'ports_mappings', 'base_directory', 'publish_directory', 'health_check_enabled', 'health_check_path', 'health_check_port', 'health_check_host', 'health_check_method', 'health_check_return_code', 'health_check_scheme', 'health_check_response_text', 'health_check_interval', 'health_check_timeout', 'health_check_retries', 'health_check_start_period', 'limits_memory', 'limits_memory_swap', 'limits_memory_swappiness', 'limits_memory_reservation', 'limits_cpus', 'limits_cpuset', 'limits_cpu_shares', 'custom_labels', 'custom_docker_run_options', 'post_deployment_command', 'post_deployment_command_container', 'pre_deployment_command', 'pre_deployment_command_container',  'manual_webhook_secret_github', 'manual_webhook_secret_gitlab', 'manual_webhook_secret_bitbucket', 'manual_webhook_secret_gitea', 'redirect', 'github_app_uuid', 'instant_deploy', 'dockerfile', 'docker_compose_location', 'docker_compose_raw', 'docker_compose_custom_start_command', 'docker_compose_custom_build_command', 'docker_compose_domains', 'watch_paths'];
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
            'project_uuid' => 'string|required',
            'environment_name' => 'string|required',
            'server_uuid' => 'string|required',
            'destination_uuid' => 'string',
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
        $instantDeploy = $request->instant_deploy;
        $githubAppUuid = $request->github_app_uuid;

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
                'docker_compose_location' => 'string',
                'docker_compose_raw' => 'string|nullable',
                'docker_compose_domains' => 'array|nullable',
                'docker_compose_custom_start_command' => 'string|nullable',
                'docker_compose_custom_build_command' => 'string|nullable',
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
            removeUnnecessaryFieldsFromRequest($request);

            $application->fill($request->all());
            $dockerComposeDomainsJson = collect();
            if ($request->has('docker_compose_domains')) {
                $dockerComposeDomains = collect($request->docker_compose_domains);
                if ($dockerComposeDomains->count() > 0) {
                    $dockerComposeDomains->each(function ($domain, $key) use ($dockerComposeDomainsJson) {
                        $dockerComposeDomainsJson->put(data_get($domain, 'name'), ['domain' => data_get($domain, 'domain')]);
                    });
                }
                $request->offsetUnset('docker_compose_domains');
            }
            if ($dockerComposeDomainsJson->count() > 0) {
                $application->docker_compose_domains = $dockerComposeDomainsJson;
            }

            $application->fqdn = $fqdn;
            $application->destination_id = $destination->id;
            $application->destination_type = $destination->getMorphClass();
            $application->environment_id = $environment->id;
            $application->save();
            $application->refresh();
            $application->custom_labels = str(implode('|coolify|', generateLabelsApplication($application)))->replace('|coolify|', "\n");
            $application->save();
            $application->isConfigurationChanged(true);

            if ($instantDeploy) {
                $deployment_uuid = new Cuid2(7);

                queue_application_deployment(
                    application: $application,
                    deployment_uuid: $deployment_uuid,
                    no_questions_asked: true,
                    is_api: true,
                );
            } else {
                if ($application->build_pack === 'dockercompose') {
                    LoadComposeFile::dispatch($application);
                }
            }

            return response()->json(serializeApiResponse([
                'uuid' => data_get($application, 'uuid'),
                'domains' => data_get($application, 'domains'),
            ]));
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
                'watch_paths' => 'string|nullable',
                'docker_compose_location' => 'string',
                'docker_compose_raw' => 'string|nullable',
                'docker_compose_domains' => 'array|nullable',
                'docker_compose_custom_start_command' => 'string|nullable',
                'docker_compose_custom_build_command' => 'string|nullable',
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
                return response()->json(['message' => 'Github App not found.'], 404);
            }
            $gitRepository = $request->git_repository;
            if (str($gitRepository)->startsWith('http') || str($gitRepository)->contains('github.com')) {
                $gitRepository = str($gitRepository)->replace('https://', '')->replace('http://', '')->replace('github.com/', '');
            }
            $application = new Application();
            removeUnnecessaryFieldsFromRequest($request);

            $application->fill($request->all());

            $dockerComposeDomainsJson = collect();
            if ($request->has('docker_compose_domains')) {
                $yaml = Yaml::parse($application->docker_compose_raw);
                $services = data_get($yaml, 'services');
                $dockerComposeDomains = collect($request->docker_compose_domains);
                if ($dockerComposeDomains->count() > 0) {
                    $dockerComposeDomains->each(function ($domain, $key) use ($services, $dockerComposeDomainsJson) {
                        $name = data_get($domain, 'name');
                        if (data_get($services, $name)) {
                            $dockerComposeDomainsJson->put($name, ['domain' => data_get($domain, 'domain')]);
                        }
                    });
                }
                $request->offsetUnset('docker_compose_domains');
            }
            if ($dockerComposeDomainsJson->count() > 0) {
                $application->docker_compose_domains = $dockerComposeDomainsJson;
            }
            $application->fqdn = $fqdn;
            $application->git_repository = $gitRepository;
            $application->destination_id = $destination->id;
            $application->destination_type = $destination->getMorphClass();
            $application->environment_id = $environment->id;
            $application->source_type = $githubApp->getMorphClass();
            $application->source_id = $githubApp->id;
            $application->save();
            $application->refresh();
            $application->custom_labels = str(implode('|coolify|', generateLabelsApplication($application)))->replace('|coolify|', "\n");
            $application->save();
            $application->isConfigurationChanged(true);

            if ($instantDeploy) {
                $deployment_uuid = new Cuid2(7);

                queue_application_deployment(
                    application: $application,
                    deployment_uuid: $deployment_uuid,
                    no_questions_asked: true,
                    is_api: true,
                );
            } else {
                if ($application->build_pack === 'dockercompose') {
                    LoadComposeFile::dispatch($application);
                }
            }

            return response()->json(serializeApiResponse([
                'uuid' => data_get($application, 'uuid'),
                'domains' => data_get($application, 'domains'),
            ]));
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
                'watch_paths' => 'string|nullable',
                'docker_compose_location' => 'string',
                'docker_compose_raw' => 'string|nullable',
                'docker_compose_domains' => 'array|nullable',
                'docker_compose_custom_start_command' => 'string|nullable',
                'docker_compose_custom_build_command' => 'string|nullable',
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
                return response()->json(['message' => 'Private Key not found.'], 404);
            }

            $application = new Application();
            removeUnnecessaryFieldsFromRequest($request);

            $application->fill($request->all());

            $dockerComposeDomainsJson = collect();
            if ($request->has('docker_compose_domains')) {
                $yaml = Yaml::parse($application->docker_compose_raw);
                $services = data_get($yaml, 'services');
                $dockerComposeDomains = collect($request->docker_compose_domains);
                if ($dockerComposeDomains->count() > 0) {
                    $dockerComposeDomains->each(function ($domain, $key) use ($services, $dockerComposeDomainsJson) {
                        $name = data_get($domain, 'name');
                        if (data_get($services, $name)) {
                            $dockerComposeDomainsJson->put($name, ['domain' => data_get($domain, 'domain')]);
                        }
                    });
                }
                $request->offsetUnset('docker_compose_domains');
            }
            if ($dockerComposeDomainsJson->count() > 0) {
                $application->docker_compose_domains = $dockerComposeDomainsJson;
            }
            $application->fqdn = $fqdn;
            $application->private_key_id = $privateKey->id;
            $application->destination_id = $destination->id;
            $application->destination_type = $destination->getMorphClass();
            $application->environment_id = $environment->id;
            $application->save();
            $application->refresh();
            $application->custom_labels = str(implode('|coolify|', generateLabelsApplication($application)))->replace('|coolify|', "\n");
            $application->save();
            $application->isConfigurationChanged(true);

            if ($instantDeploy) {
                $deployment_uuid = new Cuid2(7);

                queue_application_deployment(
                    application: $application,
                    deployment_uuid: $deployment_uuid,
                    no_questions_asked: true,
                    is_api: true,
                );
            } else {
                if ($application->build_pack === 'dockercompose') {
                    LoadComposeFile::dispatch($application);
                }
            }

            return response()->json(serializeApiResponse([
                'uuid' => data_get($application, 'uuid'),
                'domains' => data_get($application, 'domains'),
            ]));
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
            removeUnnecessaryFieldsFromRequest($request);

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
            $application->refresh();
            $application->custom_labels = str(implode('|coolify|', generateLabelsApplication($application)))->replace('|coolify|', "\n");
            $application->save();
            $application->isConfigurationChanged(true);

            if ($instantDeploy) {
                $deployment_uuid = new Cuid2(7);

                queue_application_deployment(
                    application: $application,
                    deployment_uuid: $deployment_uuid,
                    no_questions_asked: true,
                    is_api: true,
                );
            }

            return response()->json(serializeApiResponse([
                'uuid' => data_get($application, 'uuid'),
                'domains' => data_get($application, 'domains'),
            ]));
        } elseif ($type === 'docker-image') {
            if (! $request->has('name')) {
                $request->offsetSet('name', 'docker-image-'.new Cuid2(7));
            }
            $validator = customApiValidator($request->all(), [
                sharedDataApplications(),
                'docker_registry_image_name' => 'string|required',
                'docker_registry_image_tag' => 'string',
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
            if (! $request->docker_registry_image_tag) {
                $request->offsetSet('docker_registry_image_tag', 'latest');
            }
            $application = new Application();
            removeUnnecessaryFieldsFromRequest($request);

            $application->fill($request->all());
            $application->fqdn = $fqdn;
            $application->build_pack = 'dockerimage';
            $application->destination_id = $destination->id;
            $application->destination_type = $destination->getMorphClass();
            $application->environment_id = $environment->id;

            $application->git_repository = 'coollabsio/coolify';
            $application->git_branch = 'main';
            $application->save();
            $application->refresh();
            $application->custom_labels = str(implode('|coolify|', generateLabelsApplication($application)))->replace('|coolify|', "\n");
            $application->save();
            $application->isConfigurationChanged(true);

            if ($instantDeploy) {
                $deployment_uuid = new Cuid2(7);

                queue_application_deployment(
                    application: $application,
                    deployment_uuid: $deployment_uuid,
                    no_questions_asked: true,
                    is_api: true,
                );
            }

            return response()->json(serializeApiResponse([
                'uuid' => data_get($application, 'uuid'),
                'domains' => data_get($application, 'domains'),
            ]));
        } elseif ($type === 'dockercompose') {
            $allowedFields = ['project_uuid', 'environment_name', 'server_uuid', 'destination_uuid', 'type', 'name', 'description', 'instant_deploy', 'docker_compose_raw'];

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
            if (! $request->has('name')) {
                $request->offsetSet('name', 'service'.new Cuid2(7));
            }
            $validator = customApiValidator($request->all(), [
                sharedDataApplications(),
                'docker_compose_raw' => 'string|required',
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
            if (! isBase64Encoded($request->docker_compose_raw)) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => [
                        'docker_compose_raw' => 'The docker_compose_raw should be base64 encoded.',
                    ],
                ], 422);
            }
            $dockerComposeRaw = base64_decode($request->docker_compose_raw);
            if (mb_detect_encoding($dockerComposeRaw, 'ASCII', true) === false) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => [
                        'docker_compose_raw' => 'The docker_compose_raw should be base64 encoded.',
                    ],
                ], 422);
            }
            $dockerCompose = base64_decode($request->docker_compose_raw);
            $dockerComposeRaw = Yaml::dump(Yaml::parse($dockerCompose), 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);

            // $isValid = validateComposeFile($dockerComposeRaw, $server_id);
            // if ($isValid !== 'OK') {
            //     return $this->dispatch('error', "Invalid docker-compose file.\n$isValid");
            // }

            $service = new Service();
            removeUnnecessaryFieldsFromRequest($request);
            $service->fill($request->all());

            $service->docker_compose_raw = $dockerComposeRaw;
            $service->environment_id = $environment->id;
            $service->server_id = $server->id;
            $service->destination_id = $destination->id;
            $service->destination_type = $destination->getMorphClass();
            $service->save();

            $service->name = "service-$service->uuid";
            $service->parse(isNew: true);
            StartService::dispatch($service);

            return response()->json(serializeApiResponse([
                'uuid' => data_get($service, 'uuid'),
                'domains' => data_get($service, 'domains'),
            ]));
        }

        return response()->json(['message' => 'Invalid type.'], 400);

    }

    #[OA\Get(
        summary: 'Get',
        description: 'Get application by UUID.',
        path: '/applications/{uuid}',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Applications'],
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                in: 'path',
                description: 'UUID of the application.',
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
                description: 'Get all applications.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            ref: '#/components/schemas/Application'
                        )
                    ),
                ]),
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
    public function application_by_uuid(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $uuid = $request->route('uuid');
        if (! $uuid) {
            return response()->json(['message' => 'UUID is required.'], 400);
        }
        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $request->uuid)->first();
        if (! $application) {
            return response()->json(['message' => 'Application not found.'], 404);
        }

        return response()->json($this->removeSensitiveData($application));
    }

    public function delete_by_uuid(Request $request)
    {
        $teamId = getTeamIdFromToken();
        $cleanup = $request->query->get('cleanup') ?? false;
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        if ($request->collect()->count() == 0) {
            return response()->json([
                'message' => 'Invalid request.',
            ], 400);
        }
        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $request->uuid)->first();

        if (! $application) {
            return response()->json([
                'message' => 'Application not found',
            ], 404);
        }
        DeleteResourceJob::dispatch($application, $cleanup);

        return response()->json([
            'message' => 'Application deletion request queued.',
        ]);
    }

    public function update_by_uuid(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        if ($request->collect()->count() == 0) {
            return response()->json([
                'message' => 'Invalid request.',
            ], 400);
        }
        $return = validateIncomingRequest($request);
        if ($return instanceof \Illuminate\Http\JsonResponse) {
            return $return;
        }
        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $request->uuid)->first();

        if (! $application) {
            return response()->json([
                'message' => 'Application not found',
            ], 404);
        }
        $server = $application->destination->server;
        $allowedFields = ['name', 'description', 'is_static', 'domains', 'git_repository', 'git_branch', 'git_commit_sha', 'docker_registry_image_name', 'docker_registry_image_tag', 'build_pack', 'static_image', 'install_command', 'build_command', 'start_command', 'ports_exposes', 'ports_mappings', 'base_directory', 'publish_directory', 'health_check_enabled', 'health_check_path', 'health_check_port', 'health_check_host', 'health_check_method', 'health_check_return_code', 'health_check_scheme', 'health_check_response_text', 'health_check_interval', 'health_check_timeout', 'health_check_retries', 'health_check_start_period', 'limits_memory', 'limits_memory_swap', 'limits_memory_swappiness', 'limits_memory_reservation', 'limits_cpus', 'limits_cpuset', 'limits_cpu_shares', 'custom_labels', 'custom_docker_run_options', 'post_deployment_command', 'post_deployment_command_container', 'pre_deployment_command', 'pre_deployment_command_container', 'watch_paths', 'manual_webhook_secret_github', 'manual_webhook_secret_gitlab', 'manual_webhook_secret_bitbucket', 'manual_webhook_secret_gitea', 'docker_compose_location', 'docker_compose_raw', 'docker_compose_custom_start_command', 'docker_compose_custom_build_command', 'docker_compose_domains', 'redirect'];

        $validator = customApiValidator($request->all(), [
            sharedDataApplications(),
            'name' => 'string|max:255',
            'description' => 'string|nullable',
            'static_image' => 'string',
            'watch_paths' => 'string|nullable',
            'docker_compose_location' => 'string',
            'docker_compose_raw' => 'string|nullable',
            'docker_compose_domains' => 'array|nullable',
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

        $dockerComposeDomainsJson = collect();
        if ($request->has('docker_compose_domains')) {
            $yaml = Yaml::parse($application->docker_compose_raw);
            $services = data_get($yaml, 'services');
            $dockerComposeDomains = collect($request->docker_compose_domains);
            if ($dockerComposeDomains->count() > 0) {
                $dockerComposeDomains->each(function ($domain, $key) use ($services, $dockerComposeDomainsJson) {
                    $name = data_get($domain, 'name');
                    if (data_get($services, $name)) {
                        $dockerComposeDomainsJson->put($name, ['domain' => data_get($domain, 'domain')]);
                    }
                });
            }
            $request->offsetUnset('docker_compose_domains');
        }
        $data = $request->all();
        data_set($data, 'fqdn', $domains);
        if ($dockerComposeDomainsJson->count() > 0) {
            data_set($data, 'docker_compose_domains', json_encode($dockerComposeDomainsJson));
        }
        $application->fill($data);
        $application->save();

        return response()->json($this->removeSensitiveData($application));
    }

    public function envs(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $request->uuid)->first();

        if (! $application) {
            return response()->json([
                'message' => 'Application not found',
            ], 404);
        }
        $envs = $application->environment_variables->sortBy('id')->merge($application->environment_variables_preview->sortBy('id'));

        $envs = $envs->map(function ($env) {
            $env->makeHidden([
                'service_id',
                'standalone_clickhouse_id',
                'standalone_dragonfly_id',
                'standalone_keydb_id',
                'standalone_mariadb_id',
                'standalone_mongodb_id',
                'standalone_mysql_id',
                'standalone_postgresql_id',
                'standalone_redis_id',
            ]);
            $env = $this->removeSensitiveData($env);

            return $env;
        });

        return response()->json($envs);
    }

    public function update_env_by_uuid(Request $request)
    {
        $allowedFields = ['key', 'value', 'is_preview', 'is_build_time', 'is_literal'];
        $teamId = getTeamIdFromToken();

        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $return = validateIncomingRequest($request);
        if ($return instanceof \Illuminate\Http\JsonResponse) {
            return $return;
        }
        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $request->uuid)->first();

        if (! $application) {
            return response()->json([
                'message' => 'Application not found',
            ], 404);
        }
        $validator = customApiValidator($request->all(), [
            'key' => 'string|required',
            'value' => 'string|nullable',
            'is_preview' => 'boolean',
            'is_build_time' => 'boolean',
            'is_literal' => 'boolean',
            'is_multiline' => 'boolean',
            'is_shown_once' => 'boolean',
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
                if ($env->is_multiline != $request->is_multiline) {
                    $env->is_multiline = $request->is_multiline;
                }
                if ($env->is_shown_once != $request->is_shown_once) {
                    $env->is_shown_once = $request->is_shown_once;
                }
                $env->save();

                return response()->json($this->removeSensitiveData($env));
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
                if ($env->is_multiline != $request->is_multiline) {
                    $env->is_multiline = $request->is_multiline;
                }
                if ($env->is_shown_once != $request->is_shown_once) {
                    $env->is_shown_once = $request->is_shown_once;
                }
                $env->save();

                return response()->json($this->removeSensitiveData($env));
            } else {

                return response()->json([
                    'message' => 'Environment variable not found.',
                ], 404);

            }
        }

        return response()->json([
            'message' => 'Something is not okay. Are you okay?',
        ], 500);

    }

    public function create_bulk_envs(Request $request)
    {
        $teamId = getTeamIdFromToken();

        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $return = validateIncomingRequest($request);
        if ($return instanceof \Illuminate\Http\JsonResponse) {
            return $return;
        }
        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $request->uuid)->first();

        if (! $application) {
            return response()->json([
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
                'is_multiline' => 'boolean',
                'is_shown_once' => 'boolean',
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
            $is_multi_line = $item->get('is_multiline') ?? false;
            $is_shown_once = $item->get('is_shown_once') ?? false;
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
                    if ($env->is_multiline != $item->get('is_multiline')) {
                        $env->is_multiline = $item->get('is_multiline');
                    }
                    if ($env->is_shown_once != $item->get('is_shown_once')) {
                        $env->is_shown_once = $item->get('is_shown_once');
                    }
                    $env->save();
                } else {
                    $env = $application->environment_variables()->create([
                        'key' => $item->get('key'),
                        'value' => $item->get('value'),
                        'is_preview' => $is_preview,
                        'is_build_time' => $is_build_time,
                        'is_literal' => $is_literal,
                        'is_multiline' => $is_multi_line,
                        'is_shown_once' => $is_shown_once,
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
                    if ($env->is_multiline != $item->get('is_multiline')) {
                        $env->is_multiline = $item->get('is_multiline');
                    }
                    if ($env->is_shown_once != $item->get('is_shown_once')) {
                        $env->is_shown_once = $item->get('is_shown_once');
                    }
                    $env->save();
                } else {
                    $env = $application->environment_variables()->create([
                        'key' => $item->get('key'),
                        'value' => $item->get('value'),
                        'is_preview' => $is_preview,
                        'is_build_time' => $is_build_time,
                        'is_literal' => $is_literal,
                        'is_multiline' => $is_multi_line,
                        'is_shown_once' => $is_shown_once,
                    ]);
                }
            }
        }

        return response()->json($this->removeSensitiveData($env));
    }

    public function create_env(Request $request)
    {
        $allowedFields = ['key', 'value', 'is_preview', 'is_build_time', 'is_literal'];
        $teamId = getTeamIdFromToken();

        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $request->uuid)->first();

        if (! $application) {
            return response()->json([
                'message' => 'Application not found',
            ], 404);
        }
        $validator = customApiValidator($request->all(), [
            'key' => 'string|required',
            'value' => 'string|nullable',
            'is_preview' => 'boolean',
            'is_build_time' => 'boolean',
            'is_literal' => 'boolean',
            'is_multiline' => 'boolean',
            'is_shown_once' => 'boolean',
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
                    'is_multiline' => $request->is_multiline ?? false,
                    'is_shown_once' => $request->is_shown_once ?? false,
                ]);

                return response()->json($this->removeSensitiveData($env))->setStatusCode(201);
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
                    'is_multiline' => $request->is_multiline ?? false,
                    'is_shown_once' => $request->is_shown_once ?? false,
                ]);

                return response()->json($this->removeSensitiveData($env))->setStatusCode(201);

            }
        }

        return response()->json([
            'message' => 'Something went wrong.',
        ], 500);

    }

    public function delete_env_by_uuid(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $request->uuid)->first();

        if (! $application) {
            return response()->json([
                'message' => 'Application not found.',
            ], 404);
        }
        $found_env = EnvironmentVariable::where('uuid', $request->env_uuid)->where('application_id', $application->id)->first();
        if (! $found_env) {
            return response()->json([
                'message' => 'Environment variable not found.',
            ], 404);
        }
        $found_env->forceDelete();

        return response()->json([
            'message' => 'Environment variable deleted.',
        ]);
    }

    public function action_deploy(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $force = $request->query->get('force') ?? false;
        $instant_deploy = $request->query->get('instant_deploy') ?? false;
        $uuid = $request->route('uuid');
        if (! $uuid) {
            return response()->json(['message' => 'UUID is required.'], 400);
        }
        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $request->uuid)->first();
        if (! $application) {
            return response()->json(['message' => 'Application not found.'], 404);
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
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $uuid = $request->route('uuid');
        if (! $uuid) {
            return response()->json(['message' => 'UUID is required.'], 400);
        }
        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $request->uuid)->first();
        if (! $application) {
            return response()->json(['message' => 'Application not found.'], 404);
        }
        StopApplication::dispatch($application);

        return response()->json(
            [
                'message' => 'Application stopping request queued.',
            ],
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
        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $request->uuid)->first();
        if (! $application) {
            return response()->json(['message' => 'Application not found.'], 404);
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
        );

    }

    private function validateDataApplications(Request $request, Server $server)
    {
        $teamId = getTeamIdFromToken();

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
