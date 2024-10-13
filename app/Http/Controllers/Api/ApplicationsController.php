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
        operationId: 'list-applications',
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

    #[OA\Post(
        summary: 'Create (Public)',
        description: 'Create new application based on a public git repository.',
        path: '/applications/public',
        operationId: 'create-public-application',
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
                        required: ['project_uuid', 'server_uuid', 'environment_name', 'git_repository', 'git_branch', 'build_pack', 'ports_exposes'],
                        properties: [
                            'project_uuid' => ['type' => 'string', 'description' => 'The project UUID.'],
                            'server_uuid' => ['type' => 'string', 'description' => 'The server UUID.'],
                            'environment_name' => ['type' => 'string', 'description' => 'The environment name.'],
                            'git_repository' => ['type' => 'string', 'description' => 'The git repository URL.'],
                            'git_branch' => ['type' => 'string', 'description' => 'The git branch.'],
                            'build_pack' => ['type' => 'string', 'enum' => ['nixpacks', 'static', 'dockerfile', 'dockercompose'], 'description' => 'The build pack type.'],
                            'ports_exposes' => ['type' => 'string', 'description' => 'The ports to expose.'],
                            'destination_uuid' => ['type' => 'string', 'description' => 'The destination UUID.'],
                            'name' => ['type' => 'string', 'description' => 'The application name.'],
                            'description' => ['type' => 'string', 'description' => 'The application description.'],
                            'domains' => ['type' => 'string', 'description' => 'The application domains.'],
                            'git_commit_sha' => ['type' => 'string', 'description' => 'The git commit SHA.'],
                            'docker_registry_image_name' => ['type' => 'string', 'description' => 'The docker registry image name.'],
                            'docker_registry_image_tag' => ['type' => 'string', 'description' => 'The docker registry image tag.'],
                            'is_static' => ['type' => 'boolean', 'description' => 'The flag to indicate if the application is static.'],
                            'static_image' => ['type' => 'string', 'enum' => ['nginx:alpine'], 'description' => 'The static image.'],
                            'install_command' => ['type' => 'string', 'description' => 'The install command.'],
                            'build_command' => ['type' => 'string', 'description' => 'The build command.'],
                            'start_command' => ['type' => 'string', 'description' => 'The start command.'],
                            'ports_mappings' => ['type' => 'string', 'description' => 'The ports mappings.'],
                            'base_directory' => ['type' => 'string', 'description' => 'The base directory for all commands.'],
                            'publish_directory' => ['type' => 'string', 'description' => 'The publish directory.'],
                            'health_check_enabled' => ['type' => 'boolean', 'description' => 'Health check enabled.'],
                            'health_check_path' => ['type' => 'string', 'description' => 'Health check path.'],
                            'health_check_port' => ['type' => 'string', 'nullable' => true, 'description' => 'Health check port.'],
                            'health_check_host' => ['type' => 'string', 'nullable' => true, 'description' => 'Health check host.'],
                            'health_check_method' => ['type' => 'string', 'description' => 'Health check method.'],
                            'health_check_return_code' => ['type' => 'integer', 'description' => 'Health check return code.'],
                            'health_check_scheme' => ['type' => 'string', 'description' => 'Health check scheme.'],
                            'health_check_response_text' => ['type' => 'string', 'nullable' => true, 'description' => 'Health check response text.'],
                            'health_check_interval' => ['type' => 'integer', 'description' => 'Health check interval in seconds.'],
                            'health_check_timeout' => ['type' => 'integer', 'description' => 'Health check timeout in seconds.'],
                            'health_check_retries' => ['type' => 'integer', 'description' => 'Health check retries count.'],
                            'health_check_start_period' => ['type' => 'integer', 'description' => 'Health check start period in seconds.'],
                            'limits_memory' => ['type' => 'string', 'description' => 'Memory limit.'],
                            'limits_memory_swap' => ['type' => 'string', 'description' => 'Memory swap limit.'],
                            'limits_memory_swappiness' => ['type' => 'integer', 'description' => 'Memory swappiness.'],
                            'limits_memory_reservation' => ['type' => 'string', 'description' => 'Memory reservation.'],
                            'limits_cpus' => ['type' => 'string', 'description' => 'CPU limit.'],
                            'limits_cpuset' => ['type' => 'string', 'nullable' => true, 'description' => 'CPU set.'],
                            'limits_cpu_shares' => ['type' => 'integer', 'description' => 'CPU shares.'],
                            'custom_labels' => ['type' => 'string', 'description' => 'Custom labels.'],
                            'custom_docker_run_options' => ['type' => 'string', 'description' => 'Custom docker run options.'],
                            'post_deployment_command' => ['type' => 'string', 'description' => 'Post deployment command.'],
                            'post_deployment_command_container' => ['type' => 'string', 'description' => 'Post deployment command container.'],
                            'pre_deployment_command' => ['type' => 'string', 'description' => 'Pre deployment command.'],
                            'pre_deployment_command_container' => ['type' => 'string', 'description' => 'Pre deployment command container.'],
                            'manual_webhook_secret_github' => ['type' => 'string', 'description' => 'Manual webhook secret for Github.'],
                            'manual_webhook_secret_gitlab' => ['type' => 'string', 'description' => 'Manual webhook secret for Gitlab.'],
                            'manual_webhook_secret_bitbucket' => ['type' => 'string', 'description' => 'Manual webhook secret for Bitbucket.'],
                            'manual_webhook_secret_gitea' => ['type' => 'string', 'description' => 'Manual webhook secret for Gitea.'],
                            'redirect' => ['type' => 'string', 'nullable' => true, 'description' => 'How to set redirect with Traefik / Caddy. www<->non-www.', 'enum' => ['www', 'non-www', 'both']],
                            // 'github_app_uuid' => ['type' => 'string', 'description' => 'The Github App UUID.'],
                            'instant_deploy' => ['type' => 'boolean', 'description' => 'The flag to indicate if the application should be deployed instantly.'],
                            'dockerfile' => ['type' => 'string', 'description' => 'The Dockerfile content.'],
                            'docker_compose_location' => ['type' => 'string', 'description' => 'The Docker Compose location.'],
                            'docker_compose_raw' => ['type' => 'string', 'description' => 'The Docker Compose raw content.'],
                            'docker_compose_custom_start_command' => ['type' => 'string', 'description' => 'The Docker Compose custom start command.'],
                            'docker_compose_custom_build_command' => ['type' => 'string', 'description' => 'The Docker Compose custom build command.'],
                            'docker_compose_domains' => ['type' => 'array', 'description' => 'The Docker Compose domains.'],
                            'watch_paths' => ['type' => 'string', 'description' => 'The watch paths.'],
                            'use_build_server' => ['type' => 'boolean', 'nullable' => true, 'description' => 'Use build server.'],
                        ],
                    )),
            ]),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Application created successfully.',
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
    public function create_public_application(Request $request)
    {
        return $this->create_application($request, 'public');
    }

    #[OA\Post(
        summary: 'Create (Private - GH App)',
        description: 'Create new application based on a private repository through a Github App.',
        path: '/applications/private-github-app',
        operationId: 'create-private-github-app-application',
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
                        required: ['project_uuid', 'server_uuid', 'environment_name', 'github_app_uuid', 'git_repository', 'git_branch', 'build_pack', 'ports_exposes'],
                        properties: [
                            'project_uuid' => ['type' => 'string', 'description' => 'The project UUID.'],
                            'server_uuid' => ['type' => 'string', 'description' => 'The server UUID.'],
                            'environment_name' => ['type' => 'string', 'description' => 'The environment name.'],
                            'github_app_uuid' => ['type' => 'string', 'description' => 'The Github App UUID.'],
                            'git_repository' => ['type' => 'string', 'description' => 'The git repository URL.'],
                            'git_branch' => ['type' => 'string', 'description' => 'The git branch.'],
                            'ports_exposes' => ['type' => 'string', 'description' => 'The ports to expose.'],
                            'destination_uuid' => ['type' => 'string', 'description' => 'The destination UUID.'],
                            'build_pack' => ['type' => 'string', 'enum' => ['nixpacks', 'static', 'dockerfile', 'dockercompose'], 'description' => 'The build pack type.'],
                            'name' => ['type' => 'string', 'description' => 'The application name.'],
                            'description' => ['type' => 'string', 'description' => 'The application description.'],
                            'domains' => ['type' => 'string', 'description' => 'The application domains.'],
                            'git_commit_sha' => ['type' => 'string', 'description' => 'The git commit SHA.'],
                            'docker_registry_image_name' => ['type' => 'string', 'description' => 'The docker registry image name.'],
                            'docker_registry_image_tag' => ['type' => 'string', 'description' => 'The docker registry image tag.'],
                            'is_static' => ['type' => 'boolean', 'description' => 'The flag to indicate if the application is static.'],
                            'static_image' => ['type' => 'string', 'enum' => ['nginx:alpine'], 'description' => 'The static image.'],
                            'install_command' => ['type' => 'string', 'description' => 'The install command.'],
                            'build_command' => ['type' => 'string', 'description' => 'The build command.'],
                            'start_command' => ['type' => 'string', 'description' => 'The start command.'],
                            'ports_mappings' => ['type' => 'string', 'description' => 'The ports mappings.'],
                            'base_directory' => ['type' => 'string', 'description' => 'The base directory for all commands.'],
                            'publish_directory' => ['type' => 'string', 'description' => 'The publish directory.'],
                            'health_check_enabled' => ['type' => 'boolean', 'description' => 'Health check enabled.'],
                            'health_check_path' => ['type' => 'string', 'description' => 'Health check path.'],
                            'health_check_port' => ['type' => 'string', 'nullable' => true, 'description' => 'Health check port.'],
                            'health_check_host' => ['type' => 'string', 'nullable' => true, 'description' => 'Health check host.'],
                            'health_check_method' => ['type' => 'string', 'description' => 'Health check method.'],
                            'health_check_return_code' => ['type' => 'integer', 'description' => 'Health check return code.'],
                            'health_check_scheme' => ['type' => 'string', 'description' => 'Health check scheme.'],
                            'health_check_response_text' => ['type' => 'string', 'nullable' => true, 'description' => 'Health check response text.'],
                            'health_check_interval' => ['type' => 'integer', 'description' => 'Health check interval in seconds.'],
                            'health_check_timeout' => ['type' => 'integer', 'description' => 'Health check timeout in seconds.'],
                            'health_check_retries' => ['type' => 'integer', 'description' => 'Health check retries count.'],
                            'health_check_start_period' => ['type' => 'integer', 'description' => 'Health check start period in seconds.'],
                            'limits_memory' => ['type' => 'string', 'description' => 'Memory limit.'],
                            'limits_memory_swap' => ['type' => 'string', 'description' => 'Memory swap limit.'],
                            'limits_memory_swappiness' => ['type' => 'integer', 'description' => 'Memory swappiness.'],
                            'limits_memory_reservation' => ['type' => 'string', 'description' => 'Memory reservation.'],
                            'limits_cpus' => ['type' => 'string', 'description' => 'CPU limit.'],
                            'limits_cpuset' => ['type' => 'string', 'nullable' => true, 'description' => 'CPU set.'],
                            'limits_cpu_shares' => ['type' => 'integer', 'description' => 'CPU shares.'],
                            'custom_labels' => ['type' => 'string', 'description' => 'Custom labels.'],
                            'custom_docker_run_options' => ['type' => 'string', 'description' => 'Custom docker run options.'],
                            'post_deployment_command' => ['type' => 'string', 'description' => 'Post deployment command.'],
                            'post_deployment_command_container' => ['type' => 'string', 'description' => 'Post deployment command container.'],
                            'pre_deployment_command' => ['type' => 'string', 'description' => 'Pre deployment command.'],
                            'pre_deployment_command_container' => ['type' => 'string', 'description' => 'Pre deployment command container.'],
                            'manual_webhook_secret_github' => ['type' => 'string', 'description' => 'Manual webhook secret for Github.'],
                            'manual_webhook_secret_gitlab' => ['type' => 'string', 'description' => 'Manual webhook secret for Gitlab.'],
                            'manual_webhook_secret_bitbucket' => ['type' => 'string', 'description' => 'Manual webhook secret for Bitbucket.'],
                            'manual_webhook_secret_gitea' => ['type' => 'string', 'description' => 'Manual webhook secret for Gitea.'],
                            'redirect' => ['type' => 'string', 'nullable' => true, 'description' => 'How to set redirect with Traefik / Caddy. www<->non-www.', 'enum' => ['www', 'non-www', 'both']],
                            'instant_deploy' => ['type' => 'boolean', 'description' => 'The flag to indicate if the application should be deployed instantly.'],
                            'dockerfile' => ['type' => 'string', 'description' => 'The Dockerfile content.'],
                            'docker_compose_location' => ['type' => 'string', 'description' => 'The Docker Compose location.'],
                            'docker_compose_raw' => ['type' => 'string', 'description' => 'The Docker Compose raw content.'],
                            'docker_compose_custom_start_command' => ['type' => 'string', 'description' => 'The Docker Compose custom start command.'],
                            'docker_compose_custom_build_command' => ['type' => 'string', 'description' => 'The Docker Compose custom build command.'],
                            'docker_compose_domains' => ['type' => 'array', 'description' => 'The Docker Compose domains.'],
                            'watch_paths' => ['type' => 'string', 'description' => 'The watch paths.'],
                            'use_build_server' => ['type' => 'boolean', 'nullable' => true, 'description' => 'Use build server.'],
                        ],
                    )),
            ]),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Application created successfully.',
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
    public function create_private_gh_app_application(Request $request)
    {
        return $this->create_application($request, 'private-gh-app');
    }

    #[OA\Post(
        summary: 'Create (Private - Deploy Key)',
        description: 'Create new application based on a private repository through a Deploy Key.',
        path: '/applications/private-deploy-key',
        operationId: 'create-private-deploy-key-application',
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
                        required: ['project_uuid', 'server_uuid', 'environment_name', 'private_key_uuid', 'git_repository', 'git_branch', 'build_pack', 'ports_exposes'],
                        properties: [
                            'project_uuid' => ['type' => 'string', 'description' => 'The project UUID.'],
                            'server_uuid' => ['type' => 'string', 'description' => 'The server UUID.'],
                            'environment_name' => ['type' => 'string', 'description' => 'The environment name.'],
                            'private_key_uuid' => ['type' => 'string', 'description' => 'The private key UUID.'],
                            'git_repository' => ['type' => 'string', 'description' => 'The git repository URL.'],
                            'git_branch' => ['type' => 'string', 'description' => 'The git branch.'],
                            'ports_exposes' => ['type' => 'string', 'description' => 'The ports to expose.'],
                            'destination_uuid' => ['type' => 'string', 'description' => 'The destination UUID.'],
                            'build_pack' => ['type' => 'string', 'enum' => ['nixpacks', 'static', 'dockerfile', 'dockercompose'], 'description' => 'The build pack type.'],
                            'name' => ['type' => 'string', 'description' => 'The application name.'],
                            'description' => ['type' => 'string', 'description' => 'The application description.'],
                            'domains' => ['type' => 'string', 'description' => 'The application domains.'],
                            'git_commit_sha' => ['type' => 'string', 'description' => 'The git commit SHA.'],
                            'docker_registry_image_name' => ['type' => 'string', 'description' => 'The docker registry image name.'],
                            'docker_registry_image_tag' => ['type' => 'string', 'description' => 'The docker registry image tag.'],
                            'is_static' => ['type' => 'boolean', 'description' => 'The flag to indicate if the application is static.'],
                            'static_image' => ['type' => 'string', 'enum' => ['nginx:alpine'], 'description' => 'The static image.'],
                            'install_command' => ['type' => 'string', 'description' => 'The install command.'],
                            'build_command' => ['type' => 'string', 'description' => 'The build command.'],
                            'start_command' => ['type' => 'string', 'description' => 'The start command.'],
                            'ports_mappings' => ['type' => 'string', 'description' => 'The ports mappings.'],
                            'base_directory' => ['type' => 'string', 'description' => 'The base directory for all commands.'],
                            'publish_directory' => ['type' => 'string', 'description' => 'The publish directory.'],
                            'health_check_enabled' => ['type' => 'boolean', 'description' => 'Health check enabled.'],
                            'health_check_path' => ['type' => 'string', 'description' => 'Health check path.'],
                            'health_check_port' => ['type' => 'string', 'nullable' => true, 'description' => 'Health check port.'],
                            'health_check_host' => ['type' => 'string', 'nullable' => true, 'description' => 'Health check host.'],
                            'health_check_method' => ['type' => 'string', 'description' => 'Health check method.'],
                            'health_check_return_code' => ['type' => 'integer', 'description' => 'Health check return code.'],
                            'health_check_scheme' => ['type' => 'string', 'description' => 'Health check scheme.'],
                            'health_check_response_text' => ['type' => 'string', 'nullable' => true, 'description' => 'Health check response text.'],
                            'health_check_interval' => ['type' => 'integer', 'description' => 'Health check interval in seconds.'],
                            'health_check_timeout' => ['type' => 'integer', 'description' => 'Health check timeout in seconds.'],
                            'health_check_retries' => ['type' => 'integer', 'description' => 'Health check retries count.'],
                            'health_check_start_period' => ['type' => 'integer', 'description' => 'Health check start period in seconds.'],
                            'limits_memory' => ['type' => 'string', 'description' => 'Memory limit.'],
                            'limits_memory_swap' => ['type' => 'string', 'description' => 'Memory swap limit.'],
                            'limits_memory_swappiness' => ['type' => 'integer', 'description' => 'Memory swappiness.'],
                            'limits_memory_reservation' => ['type' => 'string', 'description' => 'Memory reservation.'],
                            'limits_cpus' => ['type' => 'string', 'description' => 'CPU limit.'],
                            'limits_cpuset' => ['type' => 'string', 'nullable' => true, 'description' => 'CPU set.'],
                            'limits_cpu_shares' => ['type' => 'integer', 'description' => 'CPU shares.'],
                            'custom_labels' => ['type' => 'string', 'description' => 'Custom labels.'],
                            'custom_docker_run_options' => ['type' => 'string', 'description' => 'Custom docker run options.'],
                            'post_deployment_command' => ['type' => 'string', 'description' => 'Post deployment command.'],
                            'post_deployment_command_container' => ['type' => 'string', 'description' => 'Post deployment command container.'],
                            'pre_deployment_command' => ['type' => 'string', 'description' => 'Pre deployment command.'],
                            'pre_deployment_command_container' => ['type' => 'string', 'description' => 'Pre deployment command container.'],
                            'manual_webhook_secret_github' => ['type' => 'string', 'description' => 'Manual webhook secret for Github.'],
                            'manual_webhook_secret_gitlab' => ['type' => 'string', 'description' => 'Manual webhook secret for Gitlab.'],
                            'manual_webhook_secret_bitbucket' => ['type' => 'string', 'description' => 'Manual webhook secret for Bitbucket.'],
                            'manual_webhook_secret_gitea' => ['type' => 'string', 'description' => 'Manual webhook secret for Gitea.'],
                            'redirect' => ['type' => 'string', 'nullable' => true, 'description' => 'How to set redirect with Traefik / Caddy. www<->non-www.', 'enum' => ['www', 'non-www', 'both']],
                            'instant_deploy' => ['type' => 'boolean', 'description' => 'The flag to indicate if the application should be deployed instantly.'],
                            'dockerfile' => ['type' => 'string', 'description' => 'The Dockerfile content.'],
                            'docker_compose_location' => ['type' => 'string', 'description' => 'The Docker Compose location.'],
                            'docker_compose_raw' => ['type' => 'string', 'description' => 'The Docker Compose raw content.'],
                            'docker_compose_custom_start_command' => ['type' => 'string', 'description' => 'The Docker Compose custom start command.'],
                            'docker_compose_custom_build_command' => ['type' => 'string', 'description' => 'The Docker Compose custom build command.'],
                            'docker_compose_domains' => ['type' => 'array', 'description' => 'The Docker Compose domains.'],
                            'watch_paths' => ['type' => 'string', 'description' => 'The watch paths.'],
                            'use_build_server' => ['type' => 'boolean', 'nullable' => true, 'description' => 'Use build server.'],
                        ],
                    )),
            ]),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Application created successfully.',
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
    public function create_private_deploy_key_application(Request $request)
    {
        return $this->create_application($request, 'private-deploy-key');
    }

    #[OA\Post(
        summary: 'Create (Dockerfile)',
        description: 'Create new application based on a simple Dockerfile.',
        path: '/applications/dockerfile',
        operationId: 'create-dockerfile-application',
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
                        required: ['project_uuid', 'server_uuid', 'environment_name', 'dockerfile'],
                        properties: [
                            'project_uuid' => ['type' => 'string', 'description' => 'The project UUID.'],
                            'server_uuid' => ['type' => 'string', 'description' => 'The server UUID.'],
                            'environment_name' => ['type' => 'string', 'description' => 'The environment name.'],
                            'dockerfile' => ['type' => 'string', 'description' => 'The Dockerfile content.'],
                            'build_pack' => ['type' => 'string', 'enum' => ['nixpacks', 'static', 'dockerfile', 'dockercompose'], 'description' => 'The build pack type.'],
                            'ports_exposes' => ['type' => 'string', 'description' => 'The ports to expose.'],
                            'destination_uuid' => ['type' => 'string', 'description' => 'The destination UUID.'],
                            'name' => ['type' => 'string', 'description' => 'The application name.'],
                            'description' => ['type' => 'string', 'description' => 'The application description.'],
                            'domains' => ['type' => 'string', 'description' => 'The application domains.'],
                            'docker_registry_image_name' => ['type' => 'string', 'description' => 'The docker registry image name.'],
                            'docker_registry_image_tag' => ['type' => 'string', 'description' => 'The docker registry image tag.'],
                            'ports_mappings' => ['type' => 'string', 'description' => 'The ports mappings.'],
                            'base_directory' => ['type' => 'string', 'description' => 'The base directory for all commands.'],
                            'health_check_enabled' => ['type' => 'boolean', 'description' => 'Health check enabled.'],
                            'health_check_path' => ['type' => 'string', 'description' => 'Health check path.'],
                            'health_check_port' => ['type' => 'string', 'nullable' => true, 'description' => 'Health check port.'],
                            'health_check_host' => ['type' => 'string', 'nullable' => true, 'description' => 'Health check host.'],
                            'health_check_method' => ['type' => 'string', 'description' => 'Health check method.'],
                            'health_check_return_code' => ['type' => 'integer', 'description' => 'Health check return code.'],
                            'health_check_scheme' => ['type' => 'string', 'description' => 'Health check scheme.'],
                            'health_check_response_text' => ['type' => 'string', 'nullable' => true, 'description' => 'Health check response text.'],
                            'health_check_interval' => ['type' => 'integer', 'description' => 'Health check interval in seconds.'],
                            'health_check_timeout' => ['type' => 'integer', 'description' => 'Health check timeout in seconds.'],
                            'health_check_retries' => ['type' => 'integer', 'description' => 'Health check retries count.'],
                            'health_check_start_period' => ['type' => 'integer', 'description' => 'Health check start period in seconds.'],
                            'limits_memory' => ['type' => 'string', 'description' => 'Memory limit.'],
                            'limits_memory_swap' => ['type' => 'string', 'description' => 'Memory swap limit.'],
                            'limits_memory_swappiness' => ['type' => 'integer', 'description' => 'Memory swappiness.'],
                            'limits_memory_reservation' => ['type' => 'string', 'description' => 'Memory reservation.'],
                            'limits_cpus' => ['type' => 'string', 'description' => 'CPU limit.'],
                            'limits_cpuset' => ['type' => 'string', 'nullable' => true, 'description' => 'CPU set.'],
                            'limits_cpu_shares' => ['type' => 'integer', 'description' => 'CPU shares.'],
                            'custom_labels' => ['type' => 'string', 'description' => 'Custom labels.'],
                            'custom_docker_run_options' => ['type' => 'string', 'description' => 'Custom docker run options.'],
                            'post_deployment_command' => ['type' => 'string', 'description' => 'Post deployment command.'],
                            'post_deployment_command_container' => ['type' => 'string', 'description' => 'Post deployment command container.'],
                            'pre_deployment_command' => ['type' => 'string', 'description' => 'Pre deployment command.'],
                            'pre_deployment_command_container' => ['type' => 'string', 'description' => 'Pre deployment command container.'],
                            'manual_webhook_secret_github' => ['type' => 'string', 'description' => 'Manual webhook secret for Github.'],
                            'manual_webhook_secret_gitlab' => ['type' => 'string', 'description' => 'Manual webhook secret for Gitlab.'],
                            'manual_webhook_secret_bitbucket' => ['type' => 'string', 'description' => 'Manual webhook secret for Bitbucket.'],
                            'manual_webhook_secret_gitea' => ['type' => 'string', 'description' => 'Manual webhook secret for Gitea.'],
                            'redirect' => ['type' => 'string', 'nullable' => true, 'description' => 'How to set redirect with Traefik / Caddy. www<->non-www.', 'enum' => ['www', 'non-www', 'both']],
                            'instant_deploy' => ['type' => 'boolean', 'description' => 'The flag to indicate if the application should be deployed instantly.'],
                            'use_build_server' => ['type' => 'boolean', 'nullable' => true, 'description' => 'Use build server.'],
                        ],
                    )),
            ]),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Application created successfully.',
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
    public function create_dockerfile_application(Request $request)
    {
        return $this->create_application($request, 'dockerfile');
    }

    #[OA\Post(
        summary: 'Create (Docker Image)',
        description: 'Create new application based on a prebuilt docker image',
        path: '/applications/dockerimage',
        operationId: 'create-dockerimage-application',
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
                        required: ['project_uuid', 'server_uuid', 'environment_name', 'docker_registry_image_name', 'ports_exposes'],
                        properties: [
                            'project_uuid' => ['type' => 'string', 'description' => 'The project UUID.'],
                            'server_uuid' => ['type' => 'string', 'description' => 'The server UUID.'],
                            'environment_name' => ['type' => 'string', 'description' => 'The environment name.'],
                            'docker_registry_image_name' => ['type' => 'string', 'description' => 'The docker registry image name.'],
                            'docker_registry_image_tag' => ['type' => 'string', 'description' => 'The docker registry image tag.'],
                            'ports_exposes' => ['type' => 'string', 'description' => 'The ports to expose.'],
                            'destination_uuid' => ['type' => 'string', 'description' => 'The destination UUID.'],
                            'name' => ['type' => 'string', 'description' => 'The application name.'],
                            'description' => ['type' => 'string', 'description' => 'The application description.'],
                            'domains' => ['type' => 'string', 'description' => 'The application domains.'],
                            'ports_mappings' => ['type' => 'string', 'description' => 'The ports mappings.'],
                            'health_check_enabled' => ['type' => 'boolean', 'description' => 'Health check enabled.'],
                            'health_check_path' => ['type' => 'string', 'description' => 'Health check path.'],
                            'health_check_port' => ['type' => 'string', 'nullable' => true, 'description' => 'Health check port.'],
                            'health_check_host' => ['type' => 'string', 'nullable' => true, 'description' => 'Health check host.'],
                            'health_check_method' => ['type' => 'string', 'description' => 'Health check method.'],
                            'health_check_return_code' => ['type' => 'integer', 'description' => 'Health check return code.'],
                            'health_check_scheme' => ['type' => 'string', 'description' => 'Health check scheme.'],
                            'health_check_response_text' => ['type' => 'string', 'nullable' => true, 'description' => 'Health check response text.'],
                            'health_check_interval' => ['type' => 'integer', 'description' => 'Health check interval in seconds.'],
                            'health_check_timeout' => ['type' => 'integer', 'description' => 'Health check timeout in seconds.'],
                            'health_check_retries' => ['type' => 'integer', 'description' => 'Health check retries count.'],
                            'health_check_start_period' => ['type' => 'integer', 'description' => 'Health check start period in seconds.'],
                            'limits_memory' => ['type' => 'string', 'description' => 'Memory limit.'],
                            'limits_memory_swap' => ['type' => 'string', 'description' => 'Memory swap limit.'],
                            'limits_memory_swappiness' => ['type' => 'integer', 'description' => 'Memory swappiness.'],
                            'limits_memory_reservation' => ['type' => 'string', 'description' => 'Memory reservation.'],
                            'limits_cpus' => ['type' => 'string', 'description' => 'CPU limit.'],
                            'limits_cpuset' => ['type' => 'string', 'nullable' => true, 'description' => 'CPU set.'],
                            'limits_cpu_shares' => ['type' => 'integer', 'description' => 'CPU shares.'],
                            'custom_labels' => ['type' => 'string', 'description' => 'Custom labels.'],
                            'custom_docker_run_options' => ['type' => 'string', 'description' => 'Custom docker run options.'],
                            'post_deployment_command' => ['type' => 'string', 'description' => 'Post deployment command.'],
                            'post_deployment_command_container' => ['type' => 'string', 'description' => 'Post deployment command container.'],
                            'pre_deployment_command' => ['type' => 'string', 'description' => 'Pre deployment command.'],
                            'pre_deployment_command_container' => ['type' => 'string', 'description' => 'Pre deployment command container.'],
                            'manual_webhook_secret_github' => ['type' => 'string', 'description' => 'Manual webhook secret for Github.'],
                            'manual_webhook_secret_gitlab' => ['type' => 'string', 'description' => 'Manual webhook secret for Gitlab.'],
                            'manual_webhook_secret_bitbucket' => ['type' => 'string', 'description' => 'Manual webhook secret for Bitbucket.'],
                            'manual_webhook_secret_gitea' => ['type' => 'string', 'description' => 'Manual webhook secret for Gitea.'],
                            'redirect' => ['type' => 'string', 'nullable' => true, 'description' => 'How to set redirect with Traefik / Caddy. www<->non-www.', 'enum' => ['www', 'non-www', 'both']],
                            'instant_deploy' => ['type' => 'boolean', 'description' => 'The flag to indicate if the application should be deployed instantly.'],
                            'use_build_server' => ['type' => 'boolean', 'nullable' => true, 'description' => 'Use build server.'],
                        ],
                    )),
            ]),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Application created successfully.',
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
    public function create_dockerimage_application(Request $request)
    {
        return $this->create_application($request, 'dockerimage');
    }

    #[OA\Post(
        summary: 'Create (Docker Compose)',
        description: 'Create new application based on a docker-compose file.',
        path: '/applications/dockercompose',
        operationId: 'create-dockercompose-application',
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
                        required: ['project_uuid', 'server_uuid', 'environment_name', 'docker_compose_raw'],
                        properties: [
                            'project_uuid' => ['type' => 'string', 'description' => 'The project UUID.'],
                            'server_uuid' => ['type' => 'string', 'description' => 'The server UUID.'],
                            'environment_name' => ['type' => 'string', 'description' => 'The environment name.'],
                            'docker_compose_raw' => ['type' => 'string', 'description' => 'The Docker Compose raw content.'],
                            'destination_uuid' => ['type' => 'string', 'description' => 'The destination UUID if the server has more than one destinations.'],
                            'name' => ['type' => 'string', 'description' => 'The application name.'],
                            'description' => ['type' => 'string', 'description' => 'The application description.'],
                            'instant_deploy' => ['type' => 'boolean', 'description' => 'The flag to indicate if the application should be deployed instantly.'],
                            'use_build_server' => ['type' => 'boolean', 'nullable' => true, 'description' => 'Use build server.'],
                        ],
                    )),
            ]),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Application created successfully.',
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
    public function create_dockercompose_application(Request $request)
    {
        return $this->create_application($request, 'dockercompose');
    }

    private function create_application(Request $request, $type)
    {
        $allowedFields = ['project_uuid', 'environment_name', 'server_uuid', 'destination_uuid', 'type', 'name', 'description', 'is_static', 'domains', 'git_repository', 'git_branch', 'git_commit_sha', 'private_key_uuid', 'docker_registry_image_name', 'docker_registry_image_tag', 'build_pack', 'install_command', 'build_command', 'start_command', 'ports_exposes', 'ports_mappings', 'base_directory', 'publish_directory', 'health_check_enabled', 'health_check_path', 'health_check_port', 'health_check_host', 'health_check_method', 'health_check_return_code', 'health_check_scheme', 'health_check_response_text', 'health_check_interval', 'health_check_timeout', 'health_check_retries', 'health_check_start_period', 'limits_memory', 'limits_memory_swap', 'limits_memory_swappiness', 'limits_memory_reservation', 'limits_cpus', 'limits_cpuset', 'limits_cpu_shares', 'custom_labels', 'custom_docker_run_options', 'post_deployment_command', 'post_deployment_command_container', 'pre_deployment_command', 'pre_deployment_command_container',  'manual_webhook_secret_github', 'manual_webhook_secret_gitlab', 'manual_webhook_secret_bitbucket', 'manual_webhook_secret_gitea', 'redirect', 'github_app_uuid', 'instant_deploy', 'dockerfile', 'docker_compose_location', 'docker_compose_raw', 'docker_compose_custom_start_command', 'docker_compose_custom_build_command', 'docker_compose_domains', 'watch_paths', 'use_build_server', 'static_image'];
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
        $useBuildServer = $request->use_build_server;
        $isStatic = $request->is_static;

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
            if ($request->build_pack === 'dockercompose') {
                $request->offsetSet('ports_exposes', '80');
            }
            $validationRules = [
                'git_repository' => 'string|required',
                'git_branch' => 'string|required',
                'build_pack' => ['required', Rule::enum(BuildPackTypes::class)],
                'ports_exposes' => 'string|regex:/^(\d+)(,\d+)*$/|required',
                'docker_compose_location' => 'string',
                'docker_compose_raw' => 'string|nullable',
                'docker_compose_domains' => 'array|nullable',
            ];
            $validationRules = array_merge($validationRules, sharedDataApplications());
            $validator = customApiValidator($request->all(), $validationRules);
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

            $application = new Application;
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
            if (isset($isStatic)) {
                $application->settings->is_static = $isStatic;
                $application->settings->save();
            }
            if (isset($useBuildServer)) {
                $application->settings->is_build_server_enabled = $useBuildServer;
                $application->settings->save();
            }
            $application->refresh();
            if (! $application->settings->is_container_label_readonly_enabled) {
                $application->custom_labels = str(implode('|coolify|', generateLabelsApplication($application)))->replace('|coolify|', "\n");
                $application->save();
            }
            $application->isConfigurationChanged(true);

            if ($instantDeploy) {
                $deployment_uuid = new Cuid2;

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
            if ($request->build_pack === 'dockercompose') {
                $request->offsetSet('ports_exposes', '80');
            }
            $validationRules = [
                'git_repository' => 'string|required',
                'git_branch' => 'string|required',
                'build_pack' => ['required', Rule::enum(BuildPackTypes::class)],
                'ports_exposes' => 'string|regex:/^(\d+)(,\d+)*$/|required',
                'github_app_uuid' => 'string|required',
                'watch_paths' => 'string|nullable',
                'docker_compose_location' => 'string',
                'docker_compose_raw' => 'string|nullable',
            ];
            $validationRules = array_merge($validationRules, sharedDataApplications());

            $validator = customApiValidator($request->all(), $validationRules);
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
            $application = new Application;
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
            if (isset($useBuildServer)) {
                $application->settings->is_build_server_enabled = $useBuildServer;
                $application->settings->save();
            }
            $application->save();
            $application->refresh();
            if (! $application->settings->is_container_label_readonly_enabled) {
                $application->custom_labels = str(implode('|coolify|', generateLabelsApplication($application)))->replace('|coolify|', "\n");
                $application->save();
            }
            $application->isConfigurationChanged(true);

            if ($instantDeploy) {
                $deployment_uuid = new Cuid2;

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
            if ($request->build_pack === 'dockercompose') {
                $request->offsetSet('ports_exposes', '80');
            }

            $validationRules = [
                'git_repository' => 'string|required',
                'git_branch' => 'string|required',
                'build_pack' => ['required', Rule::enum(BuildPackTypes::class)],
                'ports_exposes' => 'string|regex:/^(\d+)(,\d+)*$/|required',
                'private_key_uuid' => 'string|required',
                'watch_paths' => 'string|nullable',
                'docker_compose_location' => 'string',
                'docker_compose_raw' => 'string|nullable',
            ];

            $validationRules = array_merge($validationRules, sharedDataApplications());
            $validator = customApiValidator($request->all(), $validationRules);

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

            $application = new Application;
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
            if (isset($useBuildServer)) {
                $application->settings->is_build_server_enabled = $useBuildServer;
                $application->settings->save();
            }
            $application->save();
            $application->refresh();
            if (! $application->settings->is_container_label_readonly_enabled) {
                $application->custom_labels = str(implode('|coolify|', generateLabelsApplication($application)))->replace('|coolify|', "\n");
                $application->save();
            }
            $application->isConfigurationChanged(true);

            if ($instantDeploy) {
                $deployment_uuid = new Cuid2;

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
                $request->offsetSet('name', 'dockerfile-'.new Cuid2);
            }

            $validationRules = [
                'dockerfile' => 'string|required',
            ];
            $validationRules = array_merge($validationRules, sharedDataApplications());
            $validator = customApiValidator($request->all(), $validationRules);

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

            $application = new Application;
            $application->fill($request->all());
            $application->fqdn = $fqdn;
            $application->ports_exposes = $port;
            $application->build_pack = 'dockerfile';
            $application->dockerfile = $dockerFile;
            $application->destination_id = $destination->id;
            $application->destination_type = $destination->getMorphClass();
            $application->environment_id = $environment->id;
            if (isset($useBuildServer)) {
                $application->settings->is_build_server_enabled = $useBuildServer;
                $application->settings->save();
            }

            $application->git_repository = 'coollabsio/coolify';
            $application->git_branch = 'main';
            $application->save();
            $application->refresh();
            if (! $application->settings->is_container_label_readonly_enabled) {
                $application->custom_labels = str(implode('|coolify|', generateLabelsApplication($application)))->replace('|coolify|', "\n");
                $application->save();
            }
            $application->isConfigurationChanged(true);

            if ($instantDeploy) {
                $deployment_uuid = new Cuid2;

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
        } elseif ($type === 'dockerimage') {
            if (! $request->has('name')) {
                $request->offsetSet('name', 'docker-image-'.new Cuid2);
            }
            $validationRules = [
                'docker_registry_image_name' => 'string|required',
                'docker_registry_image_tag' => 'string',
                'ports_exposes' => 'string|regex:/^(\d+)(,\d+)*$/|required',
            ];
            $validationRules = array_merge($validationRules, sharedDataApplications());
            $validator = customApiValidator($request->all(), $validationRules);

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
            $application = new Application;
            removeUnnecessaryFieldsFromRequest($request);

            $application->fill($request->all());
            $application->fqdn = $fqdn;
            $application->build_pack = 'dockerimage';
            $application->destination_id = $destination->id;
            $application->destination_type = $destination->getMorphClass();
            $application->environment_id = $environment->id;
            if (isset($useBuildServer)) {
                $application->settings->is_build_server_enabled = $useBuildServer;
                $application->settings->save();
            }

            $application->git_repository = 'coollabsio/coolify';
            $application->git_branch = 'main';
            $application->save();
            $application->refresh();
            if (! $application->settings->is_container_label_readonly_enabled) {
                $application->custom_labels = str(implode('|coolify|', generateLabelsApplication($application)))->replace('|coolify|', "\n");
                $application->save();
            }
            $application->isConfigurationChanged(true);

            if ($instantDeploy) {
                $deployment_uuid = new Cuid2;

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
                $request->offsetSet('name', 'service'.new Cuid2);
            }
            $validationRules = [
                'docker_compose_raw' => 'string|required',
            ];
            $validationRules = array_merge($validationRules, sharedDataApplications());
            $validator = customApiValidator($request->all(), $validationRules);

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

            $service = new Service;
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
            if ($instantDeploy) {
                StartService::dispatch($service);
            }

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
        operationId: 'get-application-by-uuid',
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
                description: 'Get application by UUID.',
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

    #[OA\Delete(
        summary: 'Delete',
        description: 'Delete application by UUID.',
        path: '/applications/{uuid}',
        operationId: 'delete-application-by-uuid',
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
            new OA\Parameter(name: 'delete_configurations', in: 'query', required: false, description: 'Delete configurations.', schema: new OA\Schema(type: 'boolean', default: true)),
            new OA\Parameter(name: 'delete_volumes', in: 'query', required: false, description: 'Delete volumes.', schema: new OA\Schema(type: 'boolean', default: true)),
            new OA\Parameter(name: 'docker_cleanup', in: 'query', required: false, description: 'Run docker cleanup.', schema: new OA\Schema(type: 'boolean', default: true)),
            new OA\Parameter(name: 'delete_connected_networks', in: 'query', required: false, description: 'Delete connected networks.', schema: new OA\Schema(type: 'boolean', default: true)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Application deleted.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'message' => ['type' => 'string', 'example' => 'Application deleted.'],
                            ]
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
        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $request->uuid)->first();

        if (! $application) {
            return response()->json([
                'message' => 'Application not found',
            ], 404);
        }

        DeleteResourceJob::dispatch(
            resource: $application,
            deleteConfigurations: $request->query->get('delete_configurations', true),
            deleteVolumes: $request->query->get('delete_volumes', true),
            dockerCleanup: $request->query->get('docker_cleanup', true),
            deleteConnectedNetworks: $request->query->get('delete_connected_networks', true)
        );

        return response()->json([
            'message' => 'Application deletion request queued.',
        ]);
    }

    #[OA\Patch(
        summary: 'Update',
        description: 'Update application by UUID.',
        path: '/applications/{uuid}',
        operationId: 'update-application-by-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Applications'],
        requestBody: new OA\RequestBody(
            description: 'Application updated.',
            required: true,
            content: [
                new OA\MediaType(
                    mediaType: 'application/json',
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'project_uuid' => ['type' => 'string', 'description' => 'The project UUID.'],
                            'server_uuid' => ['type' => 'string', 'description' => 'The server UUID.'],
                            'environment_name' => ['type' => 'string', 'description' => 'The environment name.'],
                            'github_app_uuid' => ['type' => 'string', 'description' => 'The Github App UUID.'],
                            'git_repository' => ['type' => 'string', 'description' => 'The git repository URL.'],
                            'git_branch' => ['type' => 'string', 'description' => 'The git branch.'],
                            'ports_exposes' => ['type' => 'string', 'description' => 'The ports to expose.'],
                            'destination_uuid' => ['type' => 'string', 'description' => 'The destination UUID.'],
                            'build_pack' => ['type' => 'string', 'enum' => ['nixpacks', 'static', 'dockerfile', 'dockercompose'], 'description' => 'The build pack type.'],
                            'name' => ['type' => 'string', 'description' => 'The application name.'],
                            'description' => ['type' => 'string', 'description' => 'The application description.'],
                            'domains' => ['type' => 'string', 'description' => 'The application domains.'],
                            'git_commit_sha' => ['type' => 'string', 'description' => 'The git commit SHA.'],
                            'docker_registry_image_name' => ['type' => 'string', 'description' => 'The docker registry image name.'],
                            'docker_registry_image_tag' => ['type' => 'string', 'description' => 'The docker registry image tag.'],
                            'is_static' => ['type' => 'boolean', 'description' => 'The flag to indicate if the application is static.'],
                            'install_command' => ['type' => 'string', 'description' => 'The install command.'],
                            'build_command' => ['type' => 'string', 'description' => 'The build command.'],
                            'start_command' => ['type' => 'string', 'description' => 'The start command.'],
                            'ports_mappings' => ['type' => 'string', 'description' => 'The ports mappings.'],
                            'base_directory' => ['type' => 'string', 'description' => 'The base directory for all commands.'],
                            'publish_directory' => ['type' => 'string', 'description' => 'The publish directory.'],
                            'health_check_enabled' => ['type' => 'boolean', 'description' => 'Health check enabled.'],
                            'health_check_path' => ['type' => 'string', 'description' => 'Health check path.'],
                            'health_check_port' => ['type' => 'string', 'nullable' => true, 'description' => 'Health check port.'],
                            'health_check_host' => ['type' => 'string', 'nullable' => true, 'description' => 'Health check host.'],
                            'health_check_method' => ['type' => 'string', 'description' => 'Health check method.'],
                            'health_check_return_code' => ['type' => 'integer', 'description' => 'Health check return code.'],
                            'health_check_scheme' => ['type' => 'string', 'description' => 'Health check scheme.'],
                            'health_check_response_text' => ['type' => 'string', 'nullable' => true, 'description' => 'Health check response text.'],
                            'health_check_interval' => ['type' => 'integer', 'description' => 'Health check interval in seconds.'],
                            'health_check_timeout' => ['type' => 'integer', 'description' => 'Health check timeout in seconds.'],
                            'health_check_retries' => ['type' => 'integer', 'description' => 'Health check retries count.'],
                            'health_check_start_period' => ['type' => 'integer', 'description' => 'Health check start period in seconds.'],
                            'limits_memory' => ['type' => 'string', 'description' => 'Memory limit.'],
                            'limits_memory_swap' => ['type' => 'string', 'description' => 'Memory swap limit.'],
                            'limits_memory_swappiness' => ['type' => 'integer', 'description' => 'Memory swappiness.'],
                            'limits_memory_reservation' => ['type' => 'string', 'description' => 'Memory reservation.'],
                            'limits_cpus' => ['type' => 'string', 'description' => 'CPU limit.'],
                            'limits_cpuset' => ['type' => 'string', 'nullable' => true, 'description' => 'CPU set.'],
                            'limits_cpu_shares' => ['type' => 'integer', 'description' => 'CPU shares.'],
                            'custom_labels' => ['type' => 'string', 'description' => 'Custom labels.'],
                            'custom_docker_run_options' => ['type' => 'string', 'description' => 'Custom docker run options.'],
                            'post_deployment_command' => ['type' => 'string', 'description' => 'Post deployment command.'],
                            'post_deployment_command_container' => ['type' => 'string', 'description' => 'Post deployment command container.'],
                            'pre_deployment_command' => ['type' => 'string', 'description' => 'Pre deployment command.'],
                            'pre_deployment_command_container' => ['type' => 'string', 'description' => 'Pre deployment command container.'],
                            'manual_webhook_secret_github' => ['type' => 'string', 'description' => 'Manual webhook secret for Github.'],
                            'manual_webhook_secret_gitlab' => ['type' => 'string', 'description' => 'Manual webhook secret for Gitlab.'],
                            'manual_webhook_secret_bitbucket' => ['type' => 'string', 'description' => 'Manual webhook secret for Bitbucket.'],
                            'manual_webhook_secret_gitea' => ['type' => 'string', 'description' => 'Manual webhook secret for Gitea.'],
                            'redirect' => ['type' => 'string', 'nullable' => true, 'description' => 'How to set redirect with Traefik / Caddy. www<->non-www.', 'enum' => ['www', 'non-www', 'both']],
                            'instant_deploy' => ['type' => 'boolean', 'description' => 'The flag to indicate if the application should be deployed instantly.'],
                            'dockerfile' => ['type' => 'string', 'description' => 'The Dockerfile content.'],
                            'docker_compose_location' => ['type' => 'string', 'description' => 'The Docker Compose location.'],
                            'docker_compose_raw' => ['type' => 'string', 'description' => 'The Docker Compose raw content.'],
                            'docker_compose_custom_start_command' => ['type' => 'string', 'description' => 'The Docker Compose custom start command.'],
                            'docker_compose_custom_build_command' => ['type' => 'string', 'description' => 'The Docker Compose custom build command.'],
                            'docker_compose_domains' => ['type' => 'array', 'description' => 'The Docker Compose domains.'],
                            'watch_paths' => ['type' => 'string', 'description' => 'The watch paths.'],
                            'use_build_server' => ['type' => 'boolean', 'nullable' => true, 'description' => 'Use build server.'],
                        ],
                    )),
            ]),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Application updated.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'uuid' => ['type' => 'string'],
                            ]
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
        $allowedFields = ['name', 'description', 'is_static', 'domains', 'git_repository', 'git_branch', 'git_commit_sha', 'docker_registry_image_name', 'docker_registry_image_tag', 'build_pack', 'static_image', 'install_command', 'build_command', 'start_command', 'ports_exposes', 'ports_mappings', 'base_directory', 'publish_directory', 'health_check_enabled', 'health_check_path', 'health_check_port', 'health_check_host', 'health_check_method', 'health_check_return_code', 'health_check_scheme', 'health_check_response_text', 'health_check_interval', 'health_check_timeout', 'health_check_retries', 'health_check_start_period', 'limits_memory', 'limits_memory_swap', 'limits_memory_swappiness', 'limits_memory_reservation', 'limits_cpus', 'limits_cpuset', 'limits_cpu_shares', 'custom_labels', 'custom_docker_run_options', 'post_deployment_command', 'post_deployment_command_container', 'pre_deployment_command', 'pre_deployment_command_container', 'watch_paths', 'manual_webhook_secret_github', 'manual_webhook_secret_gitlab', 'manual_webhook_secret_bitbucket', 'manual_webhook_secret_gitea', 'docker_compose_location', 'docker_compose_raw', 'docker_compose_custom_start_command', 'docker_compose_custom_build_command', 'docker_compose_domains', 'redirect', 'instant_deploy', 'use_build_server'];

        $validationRules = [
            'name' => 'string|max:255',
            'description' => 'string|nullable',
            'static_image' => 'string',
            'watch_paths' => 'string|nullable',
            'docker_compose_location' => 'string',
            'docker_compose_raw' => 'string|nullable',
            'docker_compose_domains' => 'array|nullable',
            'docker_compose_custom_start_command' => 'string|nullable',
            'docker_compose_custom_build_command' => 'string|nullable',
        ];
        $validationRules = array_merge($validationRules, sharedDataApplications());
        $validator = customApiValidator($request->all(), $validationRules);

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
            $errors = [];
            $fqdn = $request->domains;
            $fqdn = str($fqdn)->replaceEnd(',', '')->trim();
            $fqdn = str($fqdn)->replaceStart(',', '')->trim();
            $application->fqdn = $fqdn;
            if (! $application->settings->is_container_label_readonly_enabled) {
                $customLabels = str(implode('|coolify|', generateLabelsApplication($application)))->replace('|coolify|', "\n");
                $application->custom_labels = base64_encode($customLabels);
            }
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
        $instantDeploy = $request->instant_deploy;

        $use_build_server = $request->use_build_server;

        if (isset($use_build_server)) {
            $application->settings->is_build_server_enabled = $use_build_server;
            $application->settings->save();
        }

        removeUnnecessaryFieldsFromRequest($request);

        $data = $request->all();
        data_set($data, 'fqdn', $domains);
        if ($dockerComposeDomainsJson->count() > 0) {
            data_set($data, 'docker_compose_domains', json_encode($dockerComposeDomainsJson));
        }
        $application->fill($data);
        $application->save();

        if ($instantDeploy) {
            $deployment_uuid = new Cuid2;

            queue_application_deployment(
                application: $application,
                deployment_uuid: $deployment_uuid,
                is_api: true,
            );
        }

        return response()->json([
            'uuid' => $application->uuid,
        ]);
    }

    #[OA\Get(
        summary: 'List Envs',
        description: 'List all envs by application UUID.',
        path: '/applications/{uuid}/envs',
        operationId: 'list-envs-by-application-uuid',
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
                description: 'All environment variables by application UUID.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/EnvironmentVariable')
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

    #[OA\Patch(
        summary: 'Update Env',
        description: 'Update env by application UUID.',
        path: '/applications/{uuid}/envs',
        operationId: 'update-env-by-application-uuid',
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
                            'is_preview' => ['type' => 'boolean', 'description' => 'The flag to indicate if the environment variable is used in preview deployments.'],
                            'is_build_time' => ['type' => 'boolean', 'description' => 'The flag to indicate if the environment variable is used in build time.'],
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
                            type: 'object',
                            properties: [
                                'message' => ['type' => 'string', 'example' => 'Environment variable updated.'],
                            ]
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

                return response()->json($this->removeSensitiveData($env))->setStatusCode(201);
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

                return response()->json($this->removeSensitiveData($env))->setStatusCode(201);
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

    #[OA\Patch(
        summary: 'Update Envs (Bulk)',
        description: 'Update multiple envs by application UUID.',
        path: '/applications/{uuid}/envs/bulk',
        operationId: 'update-envs-by-application-uuid',
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
                                        'is_preview' => ['type' => 'boolean', 'description' => 'The flag to indicate if the environment variable is used in preview deployments.'],
                                        'is_build_time' => ['type' => 'boolean', 'description' => 'The flag to indicate if the environment variable is used in build time.'],
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
                            type: 'object',
                            properties: [
                                'message' => ['type' => 'string', 'example' => 'Environment variables updated.'],
                            ]
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

        return response()->json($this->removeSensitiveData($env))->setStatusCode(201);
    }

    #[OA\Post(
        summary: 'Create Env',
        description: 'Create env by application UUID.',
        path: '/applications/{uuid}/envs',
        operationId: 'create-env-by-application-uuid',
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
                        'is_preview' => ['type' => 'boolean', 'description' => 'The flag to indicate if the environment variable is used in preview deployments.'],
                        'is_build_time' => ['type' => 'boolean', 'description' => 'The flag to indicate if the environment variable is used in build time.'],
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

                return response()->json([
                    'uuid' => $env->uuid,
                ])->setStatusCode(201);
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

                return response()->json([
                    'uuid' => $env->uuid,
                ])->setStatusCode(201);

            }
        }

        return response()->json([
            'message' => 'Something went wrong.',
        ], 500);

    }

    #[OA\Delete(
        summary: 'Delete Env',
        description: 'Delete env by UUID.',
        path: '/applications/{uuid}/envs/{env_uuid}',
        operationId: 'delete-env-by-application-uuid',
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
            new OA\Parameter(
                name: 'env_uuid',
                in: 'path',
                description: 'UUID of the environment variable.',
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

    #[OA\Get(
        summary: 'Start',
        description: 'Start application. `Post` request is also accepted.',
        path: '/applications/{uuid}/start',
        operationId: 'start-application-by-uuid',
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
            new OA\Parameter(
                name: 'force',
                in: 'query',
                description: 'Force rebuild.',
                schema: new OA\Schema(
                    type: 'boolean',
                    default: false,
                )
            ),
            new OA\Parameter(
                name: 'instant_deploy',
                in: 'query',
                description: 'Instant deploy (skip queuing).',
                schema: new OA\Schema(
                    type: 'boolean',
                    default: false,
                )
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Start application.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'message' => ['type' => 'string', 'example' => 'Deployment request queued.', 'description' => 'Message.'],
                                'deployment_uuid' => ['type' => 'string', 'example' => 'doogksw', 'description' => 'UUID of the deployment.'],
                            ])
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

        $deployment_uuid = new Cuid2;

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
            ],
            200
        );
    }

    #[OA\Get(
        summary: 'Stop',
        description: 'Stop application. `Post` request is also accepted.',
        path: '/applications/{uuid}/stop',
        operationId: 'stop-application-by-uuid',
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
                description: 'Stop application.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'message' => ['type' => 'string', 'example' => 'Application stopping request queued.'],
                            ]
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

    #[OA\Get(
        summary: 'Restart',
        description: 'Restart application. `Post` request is also accepted.',
        path: '/applications/{uuid}/restart',
        operationId: 'restart-application-by-uuid',
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
                description: 'Restart application.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'message' => ['type' => 'string', 'example' => 'Restart request queued.'],
                                'deployment_uuid' => ['type' => 'string', 'example' => 'doogksw', 'description' => 'UUID of the deployment.'],
                            ]
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

        $deployment_uuid = new Cuid2;

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
            ],
        );

    }

    #[OA\Post(
        summary: 'Execute Command',
        description: "Execute a command on the application's current container.",
        path: '/applications/{uuid}/execute',
        operationId: 'execute-command-application',
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
        requestBody: new OA\RequestBody(
            required: true,
            description: 'Command to execute.',
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    type: 'object',
                    properties: [
                        'command' => ['type' => 'string', 'description' => 'Command to execute.'],
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: "Execute a command on the application's current container.",
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'message' => ['type' => 'string', 'example' => 'Command executed.'],
                                'response' => ['type' => 'string'],
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
    public function execute_command_by_uuid(Request $request)
    {
        // TODO: Need to review this from security perspective, to not allow arbitrary command execution
        $allowedFields = ['command'];
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
        $return = validateIncomingRequest($request);
        if ($return instanceof \Illuminate\Http\JsonResponse) {
            return $return;
        }
        $validator = customApiValidator($request->all(), [
            'command' => 'string|required',
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

        $container = getCurrentApplicationContainerStatus($application->destination->server, $application->id)->firstOrFail();
        $status = getContainerStatus($application->destination->server, $container['Names']);

        if ($status !== 'running') {
            return response()->json([
                'message' => 'Application is not running.',
            ], 400);
        }

        $commands = collect([
            executeInDocker($container['Names'], $request->command),
        ]);

        $res = instant_remote_process(command: $commands, server: $application->destination->server);

        return response()->json([
            'message' => 'Command executed.',
            'response' => $res,
        ]);
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
            $uuid = $request->uuid;
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
            if (checkIfDomainIsAlreadyUsed($fqdn, $teamId, $uuid)) {
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
