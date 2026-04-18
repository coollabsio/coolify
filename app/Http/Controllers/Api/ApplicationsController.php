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
use App\Models\LocalFileVolume;
use App\Models\LocalPersistentVolume;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Rules\ValidGitBranch;
use App\Rules\ValidGitRepositoryUrl;
use App\Services\DockerImageParser;
use App\Support\ValidationPatterns;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;
use Spatie\Url\Url;
use Symfony\Component\Yaml\Yaml;
use Visus\Cuid2\Cuid2;

class ApplicationsController extends Controller
{
    private function removeSensitiveData($application)
    {
        $application->makeHidden([
            'id',
            'resourceable',
            'resourceable_id',
            'resourceable_type',
        ]);
        if (request()->attributes->get('can_read_sensitive', false) === false) {
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
                'http_basic_auth_password',
            ]);
        }

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
        parameters: [
            new OA\Parameter(
                name: 'tag',
                in: 'query',
                description: 'Filter applications by tag name.',
                required: false,
                schema: new OA\Schema(
                    type: 'string',
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
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/Application')
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
        ]
    )]
    public function applications(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $tagName = $request->query('tag');

        $applications = Application::ownedByCurrentTeamAPI($teamId)
            ->when($tagName, function ($query, $tagName) {
                $query->whereHas('tags', function ($query) use ($tagName) {
                    $query->where('name', $tagName);
                });
            })
            ->get()
            ->map(function ($application) {
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
                        required: ['project_uuid', 'server_uuid', 'environment_name', 'environment_uuid', 'git_repository', 'git_branch', 'build_pack', 'ports_exposes'],
                        properties: [
                            'project_uuid' => ['type' => 'string', 'description' => 'The project UUID.'],
                            'server_uuid' => ['type' => 'string', 'description' => 'The server UUID.'],
                            'environment_name' => ['type' => 'string', 'description' => 'The environment name. You need to provide at least one of environment_name or environment_uuid.'],
                            'environment_uuid' => ['type' => 'string', 'description' => 'The environment UUID. You need to provide at least one of environment_name or environment_uuid.'],
                            'git_repository' => ['type' => 'string', 'description' => 'The git repository URL.'],
                            'git_branch' => ['type' => 'string', 'description' => 'The git branch.'],
                            'build_pack' => ['type' => 'string', 'enum' => ['nixpacks', 'static', 'dockerfile', 'dockercompose'], 'description' => 'The build pack type.'],
                            'ports_exposes' => ['type' => 'string', 'description' => 'The ports to expose.'],
                            'destination_uuid' => ['type' => 'string', 'description' => 'The destination UUID.'],
                            'name' => ['type' => 'string', 'description' => 'The application name.'],
                            'description' => ['type' => 'string', 'description' => 'The application description.'],
                            'domains' => ['type' => 'string', 'description' => 'The application URLs in a comma-separated list.'],
                            'git_commit_sha' => ['type' => 'string', 'description' => 'The git commit SHA.'],
                            'docker_registry_image_name' => ['type' => 'string', 'description' => 'The docker registry image name.'],
                            'docker_registry_image_tag' => ['type' => 'string', 'description' => 'The docker registry image tag.'],
                            'is_static' => ['type' => 'boolean', 'description' => 'The flag to indicate if the application is static.'],
                            'is_spa' => ['type' => 'boolean', 'description' => 'The flag to indicate if the application is a single-page application (SPA). Only relevant when is_static is true.'],
                            'is_auto_deploy_enabled' => ['type' => 'boolean', 'description' => 'The flag to indicate if auto-deploy is enabled on git push. Defaults to true.'],
                            'is_force_https_enabled' => ['type' => 'boolean', 'description' => 'The flag to indicate if HTTPS is forced. Defaults to true.'],
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
                            'dockerfile_location' => ['type' => 'string', 'description' => 'The Dockerfile location in the repository.'],
                            'docker_compose_location' => ['type' => 'string', 'description' => 'The Docker Compose location.'],
                            'docker_compose_custom_start_command' => ['type' => 'string', 'description' => 'The Docker Compose custom start command.'],
                            'docker_compose_custom_build_command' => ['type' => 'string', 'description' => 'The Docker Compose custom build command.'],
                            'docker_compose_domains' => [
                                'type' => 'array',
                                'description' => 'Array of URLs to be applied to containers of a dockercompose application.',
                                'items' => new OA\Schema(
                                    type: 'object',
                                    properties: [
                                        'name' => ['type' => 'string', 'description' => 'The service name as defined in docker-compose.'],
                                        'domain' => ['type' => 'string', 'description' => 'Comma-separated list of URLs (e.g. "https://app.coolify.io,https://app2.coolify.io")'],
                                    ],
                                ),
                            ],
                            'watch_paths' => ['type' => 'string', 'description' => 'The watch paths.'],
                            'use_build_server' => ['type' => 'boolean', 'nullable' => true, 'description' => 'Use build server.'],
                            'is_http_basic_auth_enabled' => ['type' => 'boolean', 'description' => 'HTTP Basic Authentication enabled.'],
                            'http_basic_auth_username' => ['type' => 'string', 'nullable' => true, 'description' => 'Username for HTTP Basic Authentication'],
                            'http_basic_auth_password' => ['type' => 'string', 'nullable' => true, 'description' => 'Password for HTTP Basic Authentication'],
                            'connect_to_docker_network' => ['type' => 'boolean', 'description' => 'The flag to connect the service to the predefined Docker network.'],
                            'force_domain_override' => ['type' => 'boolean', 'description' => 'Force domain usage even if conflicts are detected. Default is false.'],
                            'autogenerate_domain' => ['type' => 'boolean', 'default' => true, 'description' => 'If true and domains is empty, auto-generate a domain using the server\'s wildcard domain or sslip.io fallback. Default: true.'],
                            'is_container_label_escape_enabled' => ['type' => 'boolean', 'default' => true, 'description' => 'Escape special characters in labels. By default, $ (and other chars) is escaped. So if you write $ in the labels, it will be saved as $$. If you want to use env variables inside the labels, turn this off.'],
                            'is_preserve_repository_enabled' => ['type' => 'boolean', 'default' => false, 'description' => 'Preserve repository during deployment.'],
                        ],
                    )
                ),
            ]
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Application created successfully.',
                content: new OA\MediaType(
                    mediaType: 'application/json',
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'uuid' => ['type' => 'string'],
                        ]
                    )
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
                response: 409,
                description: 'Domain conflicts detected.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'message' => ['type' => 'string', 'example' => 'Domain conflicts detected. Use force_domain_override=true to proceed.'],
                                'warning' => ['type' => 'string', 'example' => 'Using the same domain for multiple resources can cause routing conflicts and unpredictable behavior.'],
                                'conflicts' => [
                                    'type' => 'array',
                                    'items' => new OA\Schema(
                                        type: 'object',
                                        properties: [
                                            'domain' => ['type' => 'string', 'example' => 'example.com'],
                                            'resource_name' => ['type' => 'string', 'example' => 'My Application'],
                                            'resource_uuid' => ['type' => 'string', 'nullable' => true, 'example' => 'abc123-def456'],
                                            'resource_type' => ['type' => 'string', 'enum' => ['application', 'service', 'instance'], 'example' => 'application'],
                                            'message' => ['type' => 'string', 'example' => 'Domain example.com is already in use by application \'My Application\''],
                                        ]
                                    ),
                                ],
                            ]
                        )
                    ),
                ]
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
                        required: ['project_uuid', 'server_uuid', 'environment_name', 'environment_uuid', 'github_app_uuid', 'git_repository', 'git_branch', 'build_pack', 'ports_exposes'],
                        properties: [
                            'project_uuid' => ['type' => 'string', 'description' => 'The project UUID.'],
                            'server_uuid' => ['type' => 'string', 'description' => 'The server UUID.'],
                            'environment_name' => ['type' => 'string', 'description' => 'The environment name. You need to provide at least one of environment_name or environment_uuid.'],
                            'environment_uuid' => ['type' => 'string', 'description' => 'The environment UUID. You need to provide at least one of environment_name or environment_uuid.'],
                            'github_app_uuid' => ['type' => 'string', 'description' => 'The Github App UUID.'],
                            'git_repository' => ['type' => 'string', 'description' => 'The git repository URL.'],
                            'git_branch' => ['type' => 'string', 'description' => 'The git branch.'],
                            'ports_exposes' => ['type' => 'string', 'description' => 'The ports to expose.'],
                            'destination_uuid' => ['type' => 'string', 'description' => 'The destination UUID.'],
                            'build_pack' => ['type' => 'string', 'enum' => ['nixpacks', 'static', 'dockerfile', 'dockercompose'], 'description' => 'The build pack type.'],
                            'name' => ['type' => 'string', 'description' => 'The application name.'],
                            'description' => ['type' => 'string', 'description' => 'The application description.'],
                            'domains' => ['type' => 'string', 'description' => 'The application URLs in a comma-separated list.'],
                            'git_commit_sha' => ['type' => 'string', 'description' => 'The git commit SHA.'],
                            'docker_registry_image_name' => ['type' => 'string', 'description' => 'The docker registry image name.'],
                            'docker_registry_image_tag' => ['type' => 'string', 'description' => 'The docker registry image tag.'],
                            'is_static' => ['type' => 'boolean', 'description' => 'The flag to indicate if the application is static.'],
                            'is_spa' => ['type' => 'boolean', 'description' => 'The flag to indicate if the application is a single-page application (SPA). Only relevant when is_static is true.'],
                            'is_auto_deploy_enabled' => ['type' => 'boolean', 'description' => 'The flag to indicate if auto-deploy is enabled on git push. Defaults to true.'],
                            'is_force_https_enabled' => ['type' => 'boolean', 'description' => 'The flag to indicate if HTTPS is forced. Defaults to true.'],
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
                            'dockerfile_location' => ['type' => 'string', 'description' => 'The Dockerfile location in the repository'],
                            'docker_compose_location' => ['type' => 'string', 'description' => 'The Docker Compose location.'],
                            'docker_compose_custom_start_command' => ['type' => 'string', 'description' => 'The Docker Compose custom start command.'],
                            'docker_compose_custom_build_command' => ['type' => 'string', 'description' => 'The Docker Compose custom build command.'],
                            'docker_compose_domains' => [
                                'type' => 'array',
                                'description' => 'Array of URLs to be applied to containers of a dockercompose application.',
                                'items' => new OA\Schema(
                                    type: 'object',
                                    properties: [
                                        'name' => ['type' => 'string', 'description' => 'The service name as defined in docker-compose.'],
                                        'domain' => ['type' => 'string', 'description' => 'Comma-separated list of URLs (e.g. "https://app.coolify.io,https://app2.coolify.io")'],
                                    ],
                                ),
                            ],
                            'watch_paths' => ['type' => 'string', 'description' => 'The watch paths.'],
                            'use_build_server' => ['type' => 'boolean', 'nullable' => true, 'description' => 'Use build server.'],
                            'is_http_basic_auth_enabled' => ['type' => 'boolean', 'description' => 'HTTP Basic Authentication enabled.'],
                            'http_basic_auth_username' => ['type' => 'string', 'nullable' => true, 'description' => 'Username for HTTP Basic Authentication'],
                            'http_basic_auth_password' => ['type' => 'string', 'nullable' => true, 'description' => 'Password for HTTP Basic Authentication'],
                            'connect_to_docker_network' => ['type' => 'boolean', 'description' => 'The flag to connect the service to the predefined Docker network.'],
                            'force_domain_override' => ['type' => 'boolean', 'description' => 'Force domain usage even if conflicts are detected. Default is false.'],
                            'autogenerate_domain' => ['type' => 'boolean', 'default' => true, 'description' => 'If true and domains is empty, auto-generate a domain using the server\'s wildcard domain or sslip.io fallback. Default: true.'],
                            'is_container_label_escape_enabled' => ['type' => 'boolean', 'default' => true, 'description' => 'Escape special characters in labels. By default, $ (and other chars) is escaped. So if you write $ in the labels, it will be saved as $$. If you want to use env variables inside the labels, turn this off.'],
                            'is_preserve_repository_enabled' => ['type' => 'boolean', 'default' => false, 'description' => 'Preserve repository during deployment.'],
                        ],
                    )
                ),
            ]
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Application created successfully.',
                content: new OA\MediaType(
                    mediaType: 'application/json',
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'uuid' => ['type' => 'string'],
                        ]
                    )
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
                response: 409,
                description: 'Domain conflicts detected.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'message' => ['type' => 'string', 'example' => 'Domain conflicts detected. Use force_domain_override=true to proceed.'],
                                'warning' => ['type' => 'string', 'example' => 'Using the same domain for multiple resources can cause routing conflicts and unpredictable behavior.'],
                                'conflicts' => [
                                    'type' => 'array',
                                    'items' => new OA\Schema(
                                        type: 'object',
                                        properties: [
                                            'domain' => ['type' => 'string', 'example' => 'example.com'],
                                            'resource_name' => ['type' => 'string', 'example' => 'My Application'],
                                            'resource_uuid' => ['type' => 'string', 'nullable' => true, 'example' => 'abc123-def456'],
                                            'resource_type' => ['type' => 'string', 'enum' => ['application', 'service', 'instance'], 'example' => 'application'],
                                            'message' => ['type' => 'string', 'example' => 'Domain example.com is already in use by application \'My Application\''],
                                        ]
                                    ),
                                ],
                            ]
                        )
                    ),
                ]
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
                        required: ['project_uuid', 'server_uuid', 'environment_name', 'environment_uuid', 'private_key_uuid', 'git_repository', 'git_branch', 'build_pack', 'ports_exposes'],
                        properties: [
                            'project_uuid' => ['type' => 'string', 'description' => 'The project UUID.'],
                            'server_uuid' => ['type' => 'string', 'description' => 'The server UUID.'],
                            'environment_name' => ['type' => 'string', 'description' => 'The environment name. You need to provide at least one of environment_name or environment_uuid.'],
                            'environment_uuid' => ['type' => 'string', 'description' => 'The environment UUID. You need to provide at least one of environment_name or environment_uuid.'],
                            'private_key_uuid' => ['type' => 'string', 'description' => 'The private key UUID.'],
                            'git_repository' => ['type' => 'string', 'description' => 'The git repository URL.'],
                            'git_branch' => ['type' => 'string', 'description' => 'The git branch.'],
                            'ports_exposes' => ['type' => 'string', 'description' => 'The ports to expose.'],
                            'destination_uuid' => ['type' => 'string', 'description' => 'The destination UUID.'],
                            'build_pack' => ['type' => 'string', 'enum' => ['nixpacks', 'static', 'dockerfile', 'dockercompose'], 'description' => 'The build pack type.'],
                            'name' => ['type' => 'string', 'description' => 'The application name.'],
                            'description' => ['type' => 'string', 'description' => 'The application description.'],
                            'domains' => ['type' => 'string', 'description' => 'The application URLs in a comma-separated list.'],
                            'git_commit_sha' => ['type' => 'string', 'description' => 'The git commit SHA.'],
                            'docker_registry_image_name' => ['type' => 'string', 'description' => 'The docker registry image name.'],
                            'docker_registry_image_tag' => ['type' => 'string', 'description' => 'The docker registry image tag.'],
                            'is_static' => ['type' => 'boolean', 'description' => 'The flag to indicate if the application is static.'],
                            'is_spa' => ['type' => 'boolean', 'description' => 'The flag to indicate if the application is a single-page application (SPA). Only relevant when is_static is true.'],
                            'is_auto_deploy_enabled' => ['type' => 'boolean', 'description' => 'The flag to indicate if auto-deploy is enabled on git push. Defaults to true.'],
                            'is_force_https_enabled' => ['type' => 'boolean', 'description' => 'The flag to indicate if HTTPS is forced. Defaults to true.'],
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
                            'dockerfile_location' => ['type' => 'string', 'description' => 'The Dockerfile location in the repository.'],
                            'docker_compose_location' => ['type' => 'string', 'description' => 'The Docker Compose location.'],
                            'docker_compose_custom_start_command' => ['type' => 'string', 'description' => 'The Docker Compose custom start command.'],
                            'docker_compose_custom_build_command' => ['type' => 'string', 'description' => 'The Docker Compose custom build command.'],
                            'docker_compose_domains' => [
                                'type' => 'array',
                                'description' => 'Array of URLs to be applied to containers of a dockercompose application.',
                                'items' => new OA\Schema(
                                    type: 'object',
                                    properties: [
                                        'name' => ['type' => 'string', 'description' => 'The service name as defined in docker-compose.'],
                                        'domain' => ['type' => 'string', 'description' => 'Comma-separated list of URLs (e.g. "https://app.coolify.io,https://app2.coolify.io")'],
                                    ],
                                ),
                            ],
                            'watch_paths' => ['type' => 'string', 'description' => 'The watch paths.'],
                            'use_build_server' => ['type' => 'boolean', 'nullable' => true, 'description' => 'Use build server.'],
                            'is_http_basic_auth_enabled' => ['type' => 'boolean', 'description' => 'HTTP Basic Authentication enabled.'],
                            'http_basic_auth_username' => ['type' => 'string', 'nullable' => true, 'description' => 'Username for HTTP Basic Authentication'],
                            'http_basic_auth_password' => ['type' => 'string', 'nullable' => true, 'description' => 'Password for HTTP Basic Authentication'],
                            'connect_to_docker_network' => ['type' => 'boolean', 'description' => 'The flag to connect the service to the predefined Docker network.'],
                            'force_domain_override' => ['type' => 'boolean', 'description' => 'Force domain usage even if conflicts are detected. Default is false.'],
                            'autogenerate_domain' => ['type' => 'boolean', 'default' => true, 'description' => 'If true and domains is empty, auto-generate a domain using the server\'s wildcard domain or sslip.io fallback. Default: true.'],
                            'is_container_label_escape_enabled' => ['type' => 'boolean', 'default' => true, 'description' => 'Escape special characters in labels. By default, $ (and other chars) is escaped. So if you write $ in the labels, it will be saved as $$. If you want to use env variables inside the labels, turn this off.'],
                            'is_preserve_repository_enabled' => ['type' => 'boolean', 'default' => false, 'description' => 'Preserve repository during deployment.'],
                        ],
                    )
                ),
            ]
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Application created successfully.',
                content: new OA\MediaType(
                    mediaType: 'application/json',
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'uuid' => ['type' => 'string'],
                        ]
                    )
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
                response: 409,
                description: 'Domain conflicts detected.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'message' => ['type' => 'string', 'example' => 'Domain conflicts detected. Use force_domain_override=true to proceed.'],
                                'warning' => ['type' => 'string', 'example' => 'Using the same domain for multiple resources can cause routing conflicts and unpredictable behavior.'],
                                'conflicts' => [
                                    'type' => 'array',
                                    'items' => new OA\Schema(
                                        type: 'object',
                                        properties: [
                                            'domain' => ['type' => 'string', 'example' => 'example.com'],
                                            'resource_name' => ['type' => 'string', 'example' => 'My Application'],
                                            'resource_uuid' => ['type' => 'string', 'nullable' => true, 'example' => 'abc123-def456'],
                                            'resource_type' => ['type' => 'string', 'enum' => ['application', 'service', 'instance'], 'example' => 'application'],
                                            'message' => ['type' => 'string', 'example' => 'Domain example.com is already in use by application \'My Application\''],
                                        ]
                                    ),
                                ],
                            ]
                        )
                    ),
                ]
            ),
        ]
    )]
    public function create_private_deploy_key_application(Request $request)
    {
        return $this->create_application($request, 'private-deploy-key');
    }

    #[OA\Post(
        summary: 'Create (Dockerfile without git)',
        description: 'Create new application based on a simple Dockerfile (without git).',
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
                        required: ['project_uuid', 'server_uuid', 'environment_name', 'environment_uuid', 'dockerfile'],
                        properties: [
                            'project_uuid' => ['type' => 'string', 'description' => 'The project UUID.'],
                            'server_uuid' => ['type' => 'string', 'description' => 'The server UUID.'],
                            'environment_name' => ['type' => 'string', 'description' => 'The environment name. You need to provide at least one of environment_name or environment_uuid.'],
                            'environment_uuid' => ['type' => 'string', 'description' => 'The environment UUID. You need to provide at least one of environment_name or environment_uuid.'],
                            'dockerfile' => ['type' => 'string', 'description' => 'The Dockerfile content.'],
                            'build_pack' => ['type' => 'string', 'enum' => ['nixpacks', 'static', 'dockerfile', 'dockercompose'], 'description' => 'The build pack type.'],
                            'ports_exposes' => ['type' => 'string', 'description' => 'The ports to expose.'],
                            'destination_uuid' => ['type' => 'string', 'description' => 'The destination UUID.'],
                            'name' => ['type' => 'string', 'description' => 'The application name.'],
                            'description' => ['type' => 'string', 'description' => 'The application description.'],
                            'domains' => ['type' => 'string', 'description' => 'The application URLs in a comma-separated list.'],
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
                            'is_force_https_enabled' => ['type' => 'boolean', 'description' => 'The flag to indicate if HTTPS is forced. Defaults to true.'],
                            'use_build_server' => ['type' => 'boolean', 'nullable' => true, 'description' => 'Use build server.'],
                            'is_http_basic_auth_enabled' => ['type' => 'boolean', 'description' => 'HTTP Basic Authentication enabled.'],
                            'http_basic_auth_username' => ['type' => 'string', 'nullable' => true, 'description' => 'Username for HTTP Basic Authentication'],
                            'http_basic_auth_password' => ['type' => 'string', 'nullable' => true, 'description' => 'Password for HTTP Basic Authentication'],
                            'connect_to_docker_network' => ['type' => 'boolean', 'description' => 'The flag to connect the service to the predefined Docker network.'],
                            'force_domain_override' => ['type' => 'boolean', 'description' => 'Force domain usage even if conflicts are detected. Default is false.'],
                            'autogenerate_domain' => ['type' => 'boolean', 'default' => true, 'description' => 'If true and domains is empty, auto-generate a domain using the server\'s wildcard domain or sslip.io fallback. Default: true.'],
                            'is_container_label_escape_enabled' => ['type' => 'boolean', 'default' => true, 'description' => 'Escape special characters in labels. By default, $ (and other chars) is escaped. So if you write $ in the labels, it will be saved as $$. If you want to use env variables inside the labels, turn this off.'],
                        ],
                    )
                ),
            ]
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Application created successfully.',
                content: new OA\MediaType(
                    mediaType: 'application/json',
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'uuid' => ['type' => 'string'],
                        ]
                    )
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
                response: 409,
                description: 'Domain conflicts detected.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'message' => ['type' => 'string', 'example' => 'Domain conflicts detected. Use force_domain_override=true to proceed.'],
                                'warning' => ['type' => 'string', 'example' => 'Using the same domain for multiple resources can cause routing conflicts and unpredictable behavior.'],
                                'conflicts' => [
                                    'type' => 'array',
                                    'items' => new OA\Schema(
                                        type: 'object',
                                        properties: [
                                            'domain' => ['type' => 'string', 'example' => 'example.com'],
                                            'resource_name' => ['type' => 'string', 'example' => 'My Application'],
                                            'resource_uuid' => ['type' => 'string', 'nullable' => true, 'example' => 'abc123-def456'],
                                            'resource_type' => ['type' => 'string', 'enum' => ['application', 'service', 'instance'], 'example' => 'application'],
                                            'message' => ['type' => 'string', 'example' => 'Domain example.com is already in use by application \'My Application\''],
                                        ]
                                    ),
                                ],
                            ]
                        )
                    ),
                ]
            ),
        ]
    )]
    public function create_dockerfile_application(Request $request)
    {
        return $this->create_application($request, 'dockerfile');
    }

    #[OA\Post(
        summary: 'Create (Docker Image without git)',
        description: 'Create new application based on a prebuilt docker image (without git).',
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
                        required: ['project_uuid', 'server_uuid', 'environment_name', 'environment_uuid', 'docker_registry_image_name', 'ports_exposes'],
                        properties: [
                            'project_uuid' => ['type' => 'string', 'description' => 'The project UUID.'],
                            'server_uuid' => ['type' => 'string', 'description' => 'The server UUID.'],
                            'environment_name' => ['type' => 'string', 'description' => 'The environment name. You need to provide at least one of environment_name or environment_uuid.'],
                            'environment_uuid' => ['type' => 'string', 'description' => 'The environment UUID. You need to provide at least one of environment_name or environment_uuid.'],
                            'docker_registry_image_name' => ['type' => 'string', 'description' => 'The docker registry image name.'],
                            'docker_registry_image_tag' => ['type' => 'string', 'description' => 'The docker registry image tag.'],
                            'ports_exposes' => ['type' => 'string', 'description' => 'The ports to expose.'],
                            'destination_uuid' => ['type' => 'string', 'description' => 'The destination UUID.'],
                            'name' => ['type' => 'string', 'description' => 'The application name.'],
                            'description' => ['type' => 'string', 'description' => 'The application description.'],
                            'domains' => ['type' => 'string', 'description' => 'The application URLs in a comma-separated list.'],
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
                            'is_force_https_enabled' => ['type' => 'boolean', 'description' => 'The flag to indicate if HTTPS is forced. Defaults to true.'],
                            'use_build_server' => ['type' => 'boolean', 'nullable' => true, 'description' => 'Use build server.'],
                            'is_http_basic_auth_enabled' => ['type' => 'boolean', 'description' => 'HTTP Basic Authentication enabled.'],
                            'http_basic_auth_username' => ['type' => 'string', 'nullable' => true, 'description' => 'Username for HTTP Basic Authentication'],
                            'http_basic_auth_password' => ['type' => 'string', 'nullable' => true, 'description' => 'Password for HTTP Basic Authentication'],
                            'connect_to_docker_network' => ['type' => 'boolean', 'description' => 'The flag to connect the service to the predefined Docker network.'],
                            'force_domain_override' => ['type' => 'boolean', 'description' => 'Force domain usage even if conflicts are detected. Default is false.'],
                            'autogenerate_domain' => ['type' => 'boolean', 'default' => true, 'description' => 'If true and domains is empty, auto-generate a domain using the server\'s wildcard domain or sslip.io fallback. Default: true.'],
                            'is_container_label_escape_enabled' => ['type' => 'boolean', 'default' => true, 'description' => 'Escape special characters in labels. By default, $ (and other chars) is escaped. So if you write $ in the labels, it will be saved as $$. If you want to use env variables inside the labels, turn this off.'],
                        ],
                    )
                ),
            ]
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Application created successfully.',
                content: new OA\MediaType(
                    mediaType: 'application/json',
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'uuid' => ['type' => 'string'],
                        ]
                    )
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
                response: 409,
                description: 'Domain conflicts detected.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'message' => ['type' => 'string', 'example' => 'Domain conflicts detected. Use force_domain_override=true to proceed.'],
                                'warning' => ['type' => 'string', 'example' => 'Using the same domain for multiple resources can cause routing conflicts and unpredictable behavior.'],
                                'conflicts' => [
                                    'type' => 'array',
                                    'items' => new OA\Schema(
                                        type: 'object',
                                        properties: [
                                            'domain' => ['type' => 'string', 'example' => 'example.com'],
                                            'resource_name' => ['type' => 'string', 'example' => 'My Application'],
                                            'resource_uuid' => ['type' => 'string', 'nullable' => true, 'example' => 'abc123-def456'],
                                            'resource_type' => ['type' => 'string', 'enum' => ['application', 'service', 'instance'], 'example' => 'application'],
                                            'message' => ['type' => 'string', 'example' => 'Domain example.com is already in use by application \'My Application\''],
                                        ]
                                    ),
                                ],
                            ]
                        )
                    ),
                ]
            ),
        ]
    )]
    public function create_dockerimage_application(Request $request)
    {
        return $this->create_application($request, 'dockerimage');
    }

    /**
     * @deprecated Use POST /api/v1/services instead. This endpoint creates a Service, not an Application and is an unstable duplicate of POST /api/v1/services.
     */
    #[OA\Post(
        summary: 'Create (Docker Compose)',
        description: 'Deprecated: Use POST /api/v1/services instead.',
        path: '/applications/dockercompose',
        operationId: 'create-dockercompose-application',
        deprecated: true,
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
                        required: ['project_uuid', 'server_uuid', 'environment_name', 'environment_uuid', 'docker_compose_raw'],
                        properties: [
                            'project_uuid' => ['type' => 'string', 'description' => 'The project UUID.'],
                            'server_uuid' => ['type' => 'string', 'description' => 'The server UUID.'],
                            'environment_name' => ['type' => 'string', 'description' => 'The environment name. You need to provide at least one of environment_name or environment_uuid.'],
                            'environment_uuid' => ['type' => 'string', 'description' => 'The environment UUID. You need to provide at least one of environment_name or environment_uuid.'],
                            'docker_compose_raw' => ['type' => 'string', 'description' => 'The Docker Compose raw content.'],
                            'destination_uuid' => ['type' => 'string', 'description' => 'The destination UUID if the server has more than one destinations.'],
                            'name' => ['type' => 'string', 'description' => 'The application name.'],
                            'description' => ['type' => 'string', 'description' => 'The application description.'],
                            'instant_deploy' => ['type' => 'boolean', 'description' => 'The flag to indicate if the application should be deployed instantly.'],
                            'use_build_server' => ['type' => 'boolean', 'nullable' => true, 'description' => 'Use build server.'],
                            'connect_to_docker_network' => ['type' => 'boolean', 'description' => 'The flag to connect the service to the predefined Docker network.'],
                            'force_domain_override' => ['type' => 'boolean', 'description' => 'Force domain usage even if conflicts are detected. Default is false.'],
                            'is_container_label_escape_enabled' => ['type' => 'boolean', 'default' => true, 'description' => 'Escape special characters in labels. By default, $ (and other chars) is escaped. So if you write $ in the labels, it will be saved as $$. If you want to use env variables inside the labels, turn this off.'],
                        ],
                    )
                ),
            ]
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Application created successfully.',
                content: new OA\MediaType(
                    mediaType: 'application/json',
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'uuid' => ['type' => 'string'],
                        ]
                    )
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
                response: 409,
                description: 'Domain conflicts detected.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'message' => ['type' => 'string', 'example' => 'Domain conflicts detected. Use force_domain_override=true to proceed.'],
                                'warning' => ['type' => 'string', 'example' => 'Using the same domain for multiple resources can cause routing conflicts and unpredictable behavior.'],
                                'conflicts' => [
                                    'type' => 'array',
                                    'items' => new OA\Schema(
                                        type: 'object',
                                        properties: [
                                            'domain' => ['type' => 'string', 'example' => 'example.com'],
                                            'resource_name' => ['type' => 'string', 'example' => 'My Application'],
                                            'resource_uuid' => ['type' => 'string', 'nullable' => true, 'example' => 'abc123-def456'],
                                            'resource_type' => ['type' => 'string', 'enum' => ['application', 'service', 'instance'], 'example' => 'application'],
                                            'message' => ['type' => 'string', 'example' => 'Domain example.com is already in use by application \'My Application\''],
                                        ]
                                    ),
                                ],
                            ]
                        )
                    ),
                ]
            ),
        ]
    )]
    public function create_dockercompose_application(Request $request)
    {
        return $this->create_application($request, 'dockercompose');
    }

    private function create_application(Request $request, $type)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $this->authorize('create', Application::class);

        $return = validateIncomingRequest($request);
        if ($return instanceof JsonResponse) {
            return $return;
        }
        $allowedFields = ['project_uuid', 'environment_name', 'environment_uuid', 'server_uuid', 'destination_uuid', 'type', 'name', 'description', 'is_static', 'is_spa', 'is_auto_deploy_enabled', 'is_force_https_enabled', 'domains', 'git_repository', 'git_branch', 'git_commit_sha', 'private_key_uuid', 'docker_registry_image_name', 'docker_registry_image_tag', 'build_pack', 'install_command', 'build_command', 'start_command', 'ports_exposes', 'ports_mappings', 'custom_network_aliases', 'base_directory', 'publish_directory', 'health_check_enabled', 'health_check_type', 'health_check_command', 'health_check_path', 'health_check_port', 'health_check_host', 'health_check_method', 'health_check_return_code', 'health_check_scheme', 'health_check_response_text', 'health_check_interval', 'health_check_timeout', 'health_check_retries', 'health_check_start_period', 'limits_memory', 'limits_memory_swap', 'limits_memory_swappiness', 'limits_memory_reservation', 'limits_cpus', 'limits_cpuset', 'limits_cpu_shares', 'custom_labels', 'custom_docker_run_options', 'post_deployment_command', 'post_deployment_command_container', 'pre_deployment_command', 'pre_deployment_command_container',  'manual_webhook_secret_github', 'manual_webhook_secret_gitlab', 'manual_webhook_secret_bitbucket', 'manual_webhook_secret_gitea', 'redirect', 'github_app_uuid', 'instant_deploy', 'dockerfile', 'dockerfile_location', 'docker_compose_location', 'docker_compose_raw', 'docker_compose_custom_start_command', 'docker_compose_custom_build_command', 'docker_compose_domains', 'watch_paths', 'use_build_server', 'static_image', 'custom_nginx_configuration', 'is_http_basic_auth_enabled', 'http_basic_auth_username', 'http_basic_auth_password', 'connect_to_docker_network', 'force_domain_override', 'autogenerate_domain', 'is_container_label_escape_enabled', 'is_preserve_repository_enabled'];

        $validator = customApiValidator($request->all(), [
            'name' => 'string|max:255',
            'description' => 'string|nullable',
            'project_uuid' => 'string|required',
            'environment_name' => 'string|nullable',
            'environment_uuid' => 'string|nullable',
            'server_uuid' => 'string|required',
            'destination_uuid' => 'string',
            'is_http_basic_auth_enabled' => 'boolean',
            'http_basic_auth_username' => 'string|nullable',
            'http_basic_auth_password' => 'string|nullable',
            'autogenerate_domain' => 'boolean',
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

        $environmentUuid = $request->environment_uuid;
        $environmentName = $request->environment_name;
        if (blank($environmentUuid) && blank($environmentName)) {
            return response()->json(['message' => 'You need to provide at least one of environment_name or environment_uuid.'], 422);
        }
        $serverUuid = $request->server_uuid;
        $fqdn = $request->domains;
        $autogenerateDomain = $request->boolean('autogenerate_domain', true);
        $instantDeploy = $request->instant_deploy;
        $githubAppUuid = $request->github_app_uuid;
        $useBuildServer = $request->use_build_server;
        $isStatic = $request->is_static;
        $isSpa = $request->is_spa;
        $isAutoDeployEnabled = $request->is_auto_deploy_enabled;
        $isForceHttpsEnabled = $request->is_force_https_enabled;
        $connectToDockerNetwork = $request->connect_to_docker_network;
        $customNginxConfiguration = $request->custom_nginx_configuration;
        $isContainerLabelEscapeEnabled = $request->boolean('is_container_label_escape_enabled', true);
        $isPreserveRepositoryEnabled = $request->boolean('is_preserve_repository_enabled',false);

        if (! is_null($customNginxConfiguration)) {
            if (! isBase64Encoded($customNginxConfiguration)) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => [
                        'custom_nginx_configuration' => 'The custom_nginx_configuration should be base64 encoded.',
                    ],
                ], 422);
            }
            $customNginxConfiguration = base64_decode($customNginxConfiguration);
            if (mb_detect_encoding($customNginxConfiguration, 'UTF-8', true) === false) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => [
                        'custom_nginx_configuration' => 'The custom_nginx_configuration should be base64 encoded.',
                    ],
                ], 422);
            }
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
        if ($type === 'public') {
            $validationRules = [
                'git_repository' => ['string', 'required', new ValidGitRepositoryUrl],
                'git_branch' => ['string', 'required', new ValidGitBranch],
                'build_pack' => ['required', Rule::enum(BuildPackTypes::class)],
                'ports_exposes' => 'string|regex:/^(\d+)(,\d+)*$/|required',
                'docker_compose_domains' => 'array|nullable',
                'docker_compose_domains.*' => 'array:name,domain',
                'docker_compose_domains.*.name' => 'string|required',
                'docker_compose_domains.*.domain' => 'string|nullable',
            ];
            // ports_exposes is not required for dockercompose
            if ($request->build_pack === 'dockercompose') {
                $validationRules['ports_exposes'] = 'string';
                $request->offsetSet('ports_exposes', '80');
            }
            $validationRules = array_merge(sharedDataApplications(), $validationRules);
            $validationMessages = [
                'docker_compose_domains.*.array' => 'An item in the docker_compose_domains array has invalid fields. Only a name and domain field are supported.',
            ];
            $validator = Validator::make($request->all(), $validationRules, $validationMessages);
            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => $validator->errors(),
                ], 422);
            }
            // For dockercompose applications, domains (fqdn) field should not be used
            // Only docker_compose_domains should be used to set domains for individual services
            if ($request->build_pack === 'dockercompose' && $request->has('domains')) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => [
                        'domains' => 'The domains field cannot be used for dockercompose applications. Use docker_compose_domains instead to set domains for individual services.',
                    ],
                ], 422);
            }
            if (! $request->has('name')) {
                $request->offsetSet('name', generate_application_name($request->git_repository, $request->git_branch));
            }
            $return = $this->validateDataApplications($request, $server);
            if ($return instanceof JsonResponse) {
                return $return;
            }

            $application = new Application;
            removeUnnecessaryFieldsFromRequest($request);

            $application->fill($request->only($allowedFields));
            $dockerComposeDomainsJson = collect();
            if ($request->has('docker_compose_domains')) {
                $dockerComposeDomains = collect($request->docker_compose_domains);

                // Collect all URLs from all docker_compose_domains items
                $urls = $dockerComposeDomains->flatMap(function ($item) {
                    $domainValue = data_get($item, 'domain');
                    if (blank($domainValue)) {
                        return [];
                    }

                    return str($domainValue)->replaceStart(',', '')->replaceEnd(',', '')->trim()->explode(',')->map(fn ($url) => trim($url))->filter();
                });

                $errors = [];
                $urls = $urls->map(function ($url) use (&$errors) {
                    if (! filter_var($url, FILTER_VALIDATE_URL)) {
                        $errors[] = "Invalid URL: {$url}";

                        return $url;
                    }
                    $scheme = parse_url($url, PHP_URL_SCHEME) ?? '';
                    if (! in_array(strtolower($scheme), ['http', 'https'])) {
                        $errors[] = "Invalid URL scheme: {$scheme} for URL: {$url}. Only http and https are supported.";
                    }

                    return $url;
                });

                $duplicates = $urls->duplicates()->unique()->values();
                if ($duplicates->isNotEmpty() && ! $request->boolean('force_domain_override')) {
                    $errors[] = 'The current request contains conflicting URLs: '.implode(', ', $duplicates->toArray()).' Use force_domain_override=true to proceed.';
                }

                if (count($errors) > 0) {
                    return response()->json([
                        'message' => 'Validation failed.',
                        'errors' => ['docker_compose_domains' => $errors],
                    ], 422);
                }

                // Check for domain conflicts
                if ($urls->isNotEmpty()) {
                    $result = checkIfDomainIsAlreadyUsedViaAPI($urls, $teamId);
                    if (isset($result['error'])) {
                        return response()->json([
                            'message' => 'Validation failed.',
                            'errors' => ['docker_compose_domains' => $result['error']],
                        ], 422);
                    }

                    if ($result['hasConflicts'] && ! $request->boolean('force_domain_override')) {
                        return response()->json([
                            'message' => 'Domain conflicts detected. Use force_domain_override=true to proceed.',
                            'conflicts' => $result['conflicts'],
                            'warning' => 'Using the same domain for multiple resources can cause routing conflicts and unpredictable behavior.',
                        ], 409);
                    }
                }

                $dockerComposeDomains->each(function ($domain) use ($dockerComposeDomainsJson) {
                    $dockerComposeDomainsJson->put(data_get($domain, 'name'), ['domain' => data_get($domain, 'domain')]);
                });
                $request->offsetUnset('docker_compose_domains');
            }
            if ($dockerComposeDomainsJson->count() > 0) {
                $application->docker_compose_domains = $dockerComposeDomainsJson;
            }
            $repository_url_parsed = Url::fromString($request->git_repository);
            $git_host = $repository_url_parsed->getHost();
            if ($git_host === 'github.com') {
                $application->source_type = GithubApp::class;
                $application->source_id = GithubApp::find(0)->id;
            }
            $application->git_repository = str($repository_url_parsed->getSegment(1).'/'.$repository_url_parsed->getSegment(2))->trim()->toString();
            $application->fqdn = $fqdn;
            $application->destination_id = $destination->id;
            $application->destination_type = $destination->getMorphClass();
            $application->environment_id = $environment->id;
            $application->save();
            if (isset($isStatic)) {
                $application->settings->is_static = $isStatic;
                $application->settings->save();
            }
            if (isset($isSpa)) {
                $application->settings->is_spa = $isSpa;
                $application->settings->save();
            }
            if (isset($isAutoDeployEnabled)) {
                $application->settings->is_auto_deploy_enabled = $isAutoDeployEnabled;
                $application->settings->save();
            }
            if (isset($isForceHttpsEnabled)) {
                $application->settings->is_force_https_enabled = $isForceHttpsEnabled;
                $application->settings->save();
            }
            if (isset($connectToDockerNetwork)) {
                $application->settings->connect_to_docker_network = $connectToDockerNetwork;
                $application->settings->save();
            }
            if (isset($useBuildServer)) {
                $application->settings->is_build_server_enabled = $useBuildServer;
                $application->settings->save();
            }
            if (isset($isContainerLabelEscapeEnabled)) {
                $application->settings->is_container_label_escape_enabled = $isContainerLabelEscapeEnabled;
                $application->settings->save();
            }
            if (isset($isPreserveRepositoryEnabled)) {
                $application->settings->is_preserve_repository_enabled = $isPreserveRepositoryEnabled;
                $application->settings->save();
            }
            $application->refresh();
            // Auto-generate domain if requested and no custom domain provided
            if ($autogenerateDomain && blank($fqdn)) {
                $application->fqdn = generateUrl(server: $server, random: $application->uuid);
                $application->save();
            }
            if ($application->settings->is_container_label_readonly_enabled) {
                $application->custom_labels = str(implode('|coolify|', generateLabelsApplication($application)))->replace('|coolify|', "\n");
                $application->save();
            }
            $application->isConfigurationChanged(true);

            if ($instantDeploy) {
                $deployment_uuid = new Cuid2;

                $result = queue_application_deployment(
                    application: $application,
                    deployment_uuid: $deployment_uuid,
                    no_questions_asked: true,
                    is_api: true,
                );
                if ($result['status'] === 'skipped') {
                    return response()->json([
                        'message' => $result['message'],
                    ], 200);
                }
            } else {
                if ($application->build_pack === 'dockercompose') {
                    LoadComposeFile::dispatch($application);
                }
            }

            return response()->json(serializeApiResponse([
                'uuid' => data_get($application, 'uuid'),
                'domains' => data_get($application, 'fqdn'),
            ]))->setStatusCode(201);
        } elseif ($type === 'private-gh-app') {
            $validationRules = [
                'git_repository' => 'string|required',
                'git_branch' => ['string', 'required', new ValidGitBranch],
                'build_pack' => ['required', Rule::enum(BuildPackTypes::class)],
                'ports_exposes' => 'string|regex:/^(\d+)(,\d+)*$/|required',
                'github_app_uuid' => 'string|required',
                'watch_paths' => 'string|nullable',
                'docker_compose_domains' => 'array|nullable',
                'docker_compose_domains.*' => 'array:name,domain',
                'docker_compose_domains.*.name' => 'string|required',
                'docker_compose_domains.*.domain' => 'string|nullable',
            ];
            $validationRules = array_merge(sharedDataApplications(), $validationRules);
            $validationMessages = [
                'docker_compose_domains.*.array' => 'An item in the docker_compose_domains array has invalid fields. Only a name and domain field are supported.',
            ];
            $validator = Validator::make($request->all(), $validationRules, $validationMessages);
            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => $validator->errors(),
                ], 422);
            }
            // For dockercompose applications, domains (fqdn) field should not be used
            // Only docker_compose_domains should be used to set domains for individual services
            if ($request->build_pack === 'dockercompose' && $request->has('domains')) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => [
                        'domains' => 'The domains field cannot be used for dockercompose applications. Use docker_compose_domains instead to set domains for individual services.',
                    ],
                ], 422);
            }

            if (! $request->has('name')) {
                $request->offsetSet('name', generate_application_name($request->git_repository, $request->git_branch));
            }
            if ($request->build_pack === 'dockercompose') {
                $request->offsetSet('ports_exposes', '80');
            }

            $return = $this->validateDataApplications($request, $server);
            if ($return instanceof JsonResponse) {
                return $return;
            }
            $githubApp = GithubApp::whereTeamId($teamId)->where('uuid', $githubAppUuid)->first();
            if (! $githubApp) {
                return response()->json(['message' => 'Github App not found.'], 404);
            }
            $token = generateGithubInstallationToken($githubApp);
            if (! $token) {
                return response()->json(['message' => 'Failed to generate Github App token.'], 400);
            }

            $gitRepository = $request->git_repository;
            if (str($gitRepository)->startsWith('http') || str($gitRepository)->contains('github.com')) {
                $gitRepository = str($gitRepository)->replace('https://', '')->replace('http://', '')->replace('github.com/', '');
            }
            $gitRepository = str($gitRepository)->trim('/')->replaceEnd('.git', '')->toString();

            // Use direct API call to verify repository access instead of loading all repositories
            // This is much faster and avoids timeouts for GitHub Apps with many repositories
            $response = Http::GitHub($githubApp->api_url, $token)
                ->timeout(20)
                ->retry(3, 200, throw: false)
                ->get("/repos/{$gitRepository}");

            if ($response->status() === 404 || $response->status() === 403) {
                return response()->json(['message' => 'Repository not found or not accessible by the GitHub App.'], 404);
            }

            if (! $response->successful()) {
                return response()->json(['message' => 'Failed to verify repository access: '.($response->json()['message'] ?? 'Unknown error')], 400);
            }

            $gitRepositoryFound = $response->json();
            $repository_project_id = data_get($gitRepositoryFound, 'id');

            $application = new Application;
            removeUnnecessaryFieldsFromRequest($request);

            $application->fill($request->only($allowedFields));

            $dockerComposeDomainsJson = collect();
            if ($request->has('docker_compose_domains')) {
                $dockerComposeDomains = collect($request->docker_compose_domains);

                // Collect all URLs from all docker_compose_domains items
                $urls = $dockerComposeDomains->flatMap(function ($item) {
                    $domainValue = data_get($item, 'domain');
                    if (blank($domainValue)) {
                        return [];
                    }

                    return str($domainValue)->replaceStart(',', '')->replaceEnd(',', '')->trim()->explode(',')->map(fn ($url) => trim($url))->filter();
                });

                $errors = [];
                $urls = $urls->map(function ($url) use (&$errors) {
                    if (! filter_var($url, FILTER_VALIDATE_URL)) {
                        $errors[] = "Invalid URL: {$url}";

                        return $url;
                    }
                    $scheme = parse_url($url, PHP_URL_SCHEME) ?? '';
                    if (! in_array(strtolower($scheme), ['http', 'https'])) {
                        $errors[] = "Invalid URL scheme: {$scheme} for URL: {$url}. Only http and https are supported.";
                    }

                    return $url;
                });

                $duplicates = $urls->duplicates()->unique()->values();
                if ($duplicates->isNotEmpty() && ! $request->boolean('force_domain_override')) {
                    $errors[] = 'The current request contains conflicting URLs: '.implode(', ', $duplicates->toArray()).' Use force_domain_override=true to proceed. ';
                }

                if (count($errors) > 0) {
                    return response()->json([
                        'message' => 'Validation failed.',
                        'errors' => ['docker_compose_domains' => $errors],
                    ], 422);
                }

                // Check for domain conflicts
                if ($urls->isNotEmpty()) {
                    $result = checkIfDomainIsAlreadyUsedViaAPI($urls, $teamId);
                    if (isset($result['error'])) {
                        return response()->json([
                            'message' => 'Validation failed.',
                            'errors' => ['docker_compose_domains' => $result['error']],
                        ], 422);
                    }

                    if ($result['hasConflicts'] && ! $request->boolean('force_domain_override')) {
                        return response()->json([
                            'message' => 'Domain conflicts detected. Use force_domain_override=true to proceed.',
                            'conflicts' => $result['conflicts'],
                            'warning' => 'Using the same domain for multiple resources can cause routing conflicts and unpredictable behavior.',
                        ], 409);
                    }
                }

                $dockerComposeDomains->each(function ($domain) use ($dockerComposeDomainsJson) {
                    $dockerComposeDomainsJson->put(data_get($domain, 'name'), ['domain' => data_get($domain, 'domain')]);
                });
                $request->offsetUnset('docker_compose_domains');
            }
            if ($dockerComposeDomainsJson->count() > 0) {
                $application->docker_compose_domains = $dockerComposeDomainsJson;
            }
            $application->fqdn = $fqdn;
            $application->git_repository = str($gitRepository)->trim()->toString();
            $application->destination_id = $destination->id;
            $application->destination_type = $destination->getMorphClass();
            $application->environment_id = $environment->id;
            $application->source_type = $githubApp->getMorphClass();
            $application->source_id = $githubApp->id;
            $application->repository_project_id = $repository_project_id;

            $application->save();
            $application->refresh();
            // Auto-generate domain if requested and no custom domain provided
            if ($autogenerateDomain && blank($fqdn)) {
                $application->fqdn = generateUrl(server: $server, random: $application->uuid);
                $application->save();
            }
            if (isset($isStatic)) {
                $application->settings->is_static = $isStatic;
                $application->settings->save();
            }
            if (isset($isSpa)) {
                $application->settings->is_spa = $isSpa;
                $application->settings->save();
            }
            if (isset($isAutoDeployEnabled)) {
                $application->settings->is_auto_deploy_enabled = $isAutoDeployEnabled;
                $application->settings->save();
            }
            if (isset($isForceHttpsEnabled)) {
                $application->settings->is_force_https_enabled = $isForceHttpsEnabled;
                $application->settings->save();
            }
            if (isset($connectToDockerNetwork)) {
                $application->settings->connect_to_docker_network = $connectToDockerNetwork;
                $application->settings->save();
            }
            if (isset($useBuildServer)) {
                $application->settings->is_build_server_enabled = $useBuildServer;
                $application->settings->save();
            }
            if (isset($isContainerLabelEscapeEnabled)) {
                $application->settings->is_container_label_escape_enabled = $isContainerLabelEscapeEnabled;
                $application->settings->save();
            }
            if (isset($isPreserveRepositoryEnabled)) {
                $application->settings->is_preserve_repository_enabled = $isPreserveRepositoryEnabled;
                $application->settings->save();
            }
            if ($application->settings->is_container_label_readonly_enabled) {
                $application->custom_labels = str(implode('|coolify|', generateLabelsApplication($application)))->replace('|coolify|', "\n");
                $application->save();
            }
            $application->isConfigurationChanged(true);

            if ($instantDeploy) {
                $deployment_uuid = new Cuid2;

                $result = queue_application_deployment(
                    application: $application,
                    deployment_uuid: $deployment_uuid,
                    no_questions_asked: true,
                    is_api: true,
                );
                if ($result['status'] === 'skipped') {
                    return response()->json([
                        'message' => $result['message'],
                    ], 200);
                }
            } else {
                if ($application->build_pack === 'dockercompose') {
                    LoadComposeFile::dispatch($application);
                }
            }

            return response()->json(serializeApiResponse([
                'uuid' => data_get($application, 'uuid'),
                'domains' => data_get($application, 'fqdn'),
            ]))->setStatusCode(201);
        } elseif ($type === 'private-deploy-key') {

            $validationRules = [
                'git_repository' => ['string', 'required', new ValidGitRepositoryUrl],
                'git_branch' => ['string', 'required', new ValidGitBranch],
                'build_pack' => ['required', Rule::enum(BuildPackTypes::class)],
                'ports_exposes' => 'string|regex:/^(\d+)(,\d+)*$/|required',
                'private_key_uuid' => 'string|required',
                'watch_paths' => 'string|nullable',
                'docker_compose_domains' => 'array|nullable',
                'docker_compose_domains.*' => 'array:name,domain',
                'docker_compose_domains.*.name' => 'string|required',
                'docker_compose_domains.*.domain' => 'string|nullable',
            ];

            $validationRules = array_merge(sharedDataApplications(), $validationRules);
            $validationMessages = [
                'docker_compose_domains.*.array' => 'An item in the docker_compose_domains array has invalid fields. Only a name and domain field are supported.',
            ];
            $validator = Validator::make($request->all(), $validationRules, $validationMessages);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => $validator->errors(),
                ], 422);
            }
            // For dockercompose applications, domains (fqdn) field should not be used
            // Only docker_compose_domains should be used to set domains for individual services
            if ($request->build_pack === 'dockercompose' && $request->has('domains')) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => [
                        'domains' => 'The domains field cannot be used for dockercompose applications. Use docker_compose_domains instead to set domains for individual services.',
                    ],
                ], 422);
            }
            if (! $request->has('name')) {
                $request->offsetSet('name', generate_application_name($request->git_repository, $request->git_branch));
            }
            if ($request->build_pack === 'dockercompose') {
                $request->offsetSet('ports_exposes', '80');
            }

            $return = $this->validateDataApplications($request, $server);
            if ($return instanceof JsonResponse) {
                return $return;
            }
            $privateKey = PrivateKey::whereTeamId($teamId)->where('uuid', $request->private_key_uuid)->first();
            if (! $privateKey) {
                return response()->json(['message' => 'Private Key not found.'], 404);
            }

            $application = new Application;
            removeUnnecessaryFieldsFromRequest($request);

            $application->fill($request->only($allowedFields));

            $dockerComposeDomainsJson = collect();
            if ($request->has('docker_compose_domains')) {
                $dockerComposeDomains = collect($request->docker_compose_domains);

                // Collect all URLs from all docker_compose_domains items
                $urls = $dockerComposeDomains->flatMap(function ($item) {
                    $domainValue = data_get($item, 'domain');
                    if (blank($domainValue)) {
                        return [];
                    }

                    return str($domainValue)->replaceStart(',', '')->replaceEnd(',', '')->trim()->explode(',')->map(fn ($url) => trim($url))->filter();
                });

                $errors = [];
                $urls = $urls->map(function ($url) use (&$errors) {
                    if (! filter_var($url, FILTER_VALIDATE_URL)) {
                        $errors[] = "Invalid URL: {$url}";

                        return $url;
                    }
                    $scheme = parse_url($url, PHP_URL_SCHEME) ?? '';
                    if (! in_array(strtolower($scheme), ['http', 'https'])) {
                        $errors[] = "Invalid URL scheme: {$scheme} for URL: {$url}. Only http and https are supported.";
                    }

                    return $url;
                });

                $duplicates = $urls->duplicates()->unique()->values();
                if ($duplicates->isNotEmpty() && ! $request->boolean('force_domain_override')) {
                    $errors[] = 'The current request contains conflicting URLs: '.implode(', ', $duplicates->toArray()).' Use force_domain_override=true to proceed.';
                }

                if (count($errors) > 0) {
                    return response()->json([
                        'message' => 'Validation failed.',
                        'errors' => ['docker_compose_domains' => $errors],
                    ], 422);
                }

                // Check for domain conflicts
                if ($urls->isNotEmpty()) {
                    $result = checkIfDomainIsAlreadyUsedViaAPI($urls, $teamId);
                    if (isset($result['error'])) {
                        return response()->json([
                            'message' => 'Validation failed.',
                            'errors' => ['docker_compose_domains' => $result['error']],
                        ], 422);
                    }

                    if ($result['hasConflicts'] && ! $request->boolean('force_domain_override')) {
                        return response()->json([
                            'message' => 'Domain conflicts detected. Use force_domain_override=true to proceed.',
                            'conflicts' => $result['conflicts'],
                            'warning' => 'Using the same domain for multiple resources can cause routing conflicts and unpredictable behavior.',
                        ], 409);
                    }
                }

                $dockerComposeDomains->each(function ($domain) use ($dockerComposeDomainsJson) {
                    $dockerComposeDomainsJson->put(data_get($domain, 'name'), ['domain' => data_get($domain, 'domain')]);
                });
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
            // Auto-generate domain if requested and no custom domain provided
            if ($autogenerateDomain && blank($fqdn)) {
                $application->fqdn = generateUrl(server: $server, random: $application->uuid);
                $application->save();
            }
            if (isset($isStatic)) {
                $application->settings->is_static = $isStatic;
                $application->settings->save();
            }
            if (isset($isSpa)) {
                $application->settings->is_spa = $isSpa;
                $application->settings->save();
            }
            if (isset($isAutoDeployEnabled)) {
                $application->settings->is_auto_deploy_enabled = $isAutoDeployEnabled;
                $application->settings->save();
            }
            if (isset($isForceHttpsEnabled)) {
                $application->settings->is_force_https_enabled = $isForceHttpsEnabled;
                $application->settings->save();
            }
            if (isset($connectToDockerNetwork)) {
                $application->settings->connect_to_docker_network = $connectToDockerNetwork;
                $application->settings->save();
            }
            if (isset($useBuildServer)) {
                $application->settings->is_build_server_enabled = $useBuildServer;
                $application->settings->save();
            }
            if (isset($isContainerLabelEscapeEnabled)) {
                $application->settings->is_container_label_escape_enabled = $isContainerLabelEscapeEnabled;
                $application->settings->save();
            }
            if (isset($isPreserveRepositoryEnabled)) {
                $application->settings->is_preserve_repository_enabled = $isPreserveRepositoryEnabled;
                $application->settings->save();
            }
            if ($application->settings->is_container_label_readonly_enabled) {
                $application->custom_labels = str(implode('|coolify|', generateLabelsApplication($application)))->replace('|coolify|', "\n");
                $application->save();
            }
            $application->isConfigurationChanged(true);

            if ($instantDeploy) {
                $deployment_uuid = new Cuid2;

                $result = queue_application_deployment(
                    application: $application,
                    deployment_uuid: $deployment_uuid,
                    no_questions_asked: true,
                    is_api: true,
                );
                if ($result['status'] === 'skipped') {
                    return response()->json([
                        'message' => $result['message'],
                    ], 200);
                }
            } else {
                if ($application->build_pack === 'dockercompose') {
                    LoadComposeFile::dispatch($application);
                }
            }

            return response()->json(serializeApiResponse([
                'uuid' => data_get($application, 'uuid'),
                'domains' => data_get($application, 'fqdn'),
            ]))->setStatusCode(201);
        } elseif ($type === 'dockerfile') {
            $validationRules = [
                'dockerfile' => 'string|required',
            ];
            $validationRules = array_merge(sharedDataApplications(), $validationRules);
            $validator = customApiValidator($request->all(), $validationRules);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => $validator->errors(),
                ], 422);
            }
            if (! $request->has('name')) {
                $request->offsetSet('name', 'dockerfile-'.new Cuid2);
            }

            $return = $this->validateDataApplications($request, $server);
            if ($return instanceof JsonResponse) {
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
            if (mb_detect_encoding($dockerFile, 'UTF-8', true) === false) {
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
            $application->fill($request->only($allowedFields));
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
            // Auto-generate domain if requested and no custom domain provided
            if ($autogenerateDomain && blank($fqdn)) {
                $application->fqdn = generateUrl(server: $server, random: $application->uuid);
                $application->save();
            }
            if (isset($isForceHttpsEnabled)) {
                $application->settings->is_force_https_enabled = $isForceHttpsEnabled;
                $application->settings->save();
            }
            if (isset($connectToDockerNetwork)) {
                $application->settings->connect_to_docker_network = $connectToDockerNetwork;
                $application->settings->save();
            }
            if (isset($useBuildServer)) {
                $application->settings->is_build_server_enabled = $useBuildServer;
                $application->settings->save();
            }
            if (isset($isContainerLabelEscapeEnabled)) {
                $application->settings->is_container_label_escape_enabled = $isContainerLabelEscapeEnabled;
                $application->settings->save();
            }
            if ($application->settings->is_container_label_readonly_enabled) {
                $application->custom_labels = str(implode('|coolify|', generateLabelsApplication($application)))->replace('|coolify|', "\n");
                $application->save();
            }
            $application->isConfigurationChanged(true);

            if ($instantDeploy) {
                $deployment_uuid = new Cuid2;

                $result = queue_application_deployment(
                    application: $application,
                    deployment_uuid: $deployment_uuid,
                    no_questions_asked: true,
                    is_api: true,
                );
                if ($result['status'] === 'skipped') {
                    return response()->json([
                        'message' => $result['message'],
                    ], 200);
                }
            }

            return response()->json(serializeApiResponse([
                'uuid' => data_get($application, 'uuid'),
                'domains' => data_get($application, 'fqdn'),
            ]))->setStatusCode(201);
        } elseif ($type === 'dockerimage') {
            $validationRules = [
                'docker_registry_image_name' => 'string|required',
                'docker_registry_image_tag' => 'string',
                'ports_exposes' => 'string|regex:/^(\d+)(,\d+)*$/|required',
            ];
            $validationRules = array_merge(sharedDataApplications(), $validationRules);
            $validator = customApiValidator($request->all(), $validationRules);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => $validator->errors(),
                ], 422);
            }
            if (! $request->has('name')) {
                $request->offsetSet('name', 'docker-image-'.new Cuid2);
            }
            $return = $this->validateDataApplications($request, $server);
            if ($return instanceof JsonResponse) {
                return $return;
            }
            // Process docker image name and tag using DockerImageParser
            $dockerImageName = $request->docker_registry_image_name;
            $dockerImageTag = $request->docker_registry_image_tag;

            // Build the full Docker image string for parsing
            if ($dockerImageTag) {
                $dockerImageString = $dockerImageName.':'.$dockerImageTag;
            } else {
                $dockerImageString = $dockerImageName;
            }

            // Parse using DockerImageParser to normalize the image reference
            $parser = new DockerImageParser;
            $parser->parse($dockerImageString);

            // Get normalized image name and tag
            $normalizedImageName = $parser->getFullImageNameWithoutTag();

            // Append @sha256 to image name if using digest
            if ($parser->isImageHash() && ! str_ends_with($normalizedImageName, '@sha256')) {
                $normalizedImageName .= '@sha256';
            }

            // Set processed values back to request
            $request->offsetSet('docker_registry_image_name', $normalizedImageName);
            $request->offsetSet('docker_registry_image_tag', $parser->getTag());

            $application = new Application;
            removeUnnecessaryFieldsFromRequest($request);

            $application->fill($request->only($allowedFields));
            $application->fqdn = $fqdn;
            $application->build_pack = 'dockerimage';
            $application->destination_id = $destination->id;
            $application->destination_type = $destination->getMorphClass();
            $application->environment_id = $environment->id;

            $application->git_repository = 'coollabsio/coolify';
            $application->git_branch = 'main';
            $application->save();
            $application->refresh();
            // Auto-generate domain if requested and no custom domain provided
            if ($autogenerateDomain && blank($fqdn)) {
                $application->fqdn = generateUrl(server: $server, random: $application->uuid);
                $application->save();
            }
            if (isset($isForceHttpsEnabled)) {
                $application->settings->is_force_https_enabled = $isForceHttpsEnabled;
                $application->settings->save();
            }
            if (isset($connectToDockerNetwork)) {
                $application->settings->connect_to_docker_network = $connectToDockerNetwork;
                $application->settings->save();
            }
            if (isset($useBuildServer)) {
                $application->settings->is_build_server_enabled = $useBuildServer;
                $application->settings->save();
            }
            if (isset($isContainerLabelEscapeEnabled)) {
                $application->settings->is_container_label_escape_enabled = $isContainerLabelEscapeEnabled;
                $application->settings->save();
            }
            if ($application->settings->is_container_label_readonly_enabled) {
                $application->custom_labels = str(implode('|coolify|', generateLabelsApplication($application)))->replace('|coolify|', "\n");
                $application->save();
            }
            $application->isConfigurationChanged(true);

            if ($instantDeploy) {
                $deployment_uuid = new Cuid2;

                $result = queue_application_deployment(
                    application: $application,
                    deployment_uuid: $deployment_uuid,
                    no_questions_asked: true,
                    is_api: true,
                );
                if ($result['status'] === 'skipped') {
                    return response()->json([
                        'message' => $result['message'],
                    ], 200);
                }
            }

            return response()->json(serializeApiResponse([
                'uuid' => data_get($application, 'uuid'),
                'domains' => data_get($application, 'fqdn'),
            ]))->setStatusCode(201);
        } elseif ($type === 'dockercompose') {
            $allowedFields = ['project_uuid', 'environment_name', 'environment_uuid', 'server_uuid', 'destination_uuid', 'type', 'name', 'description', 'instant_deploy', 'docker_compose_raw', 'force_domain_override', 'is_container_label_escape_enabled'];

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
            $validationRules = array_merge(sharedDataApplications(), $validationRules);
            $validator = customApiValidator($request->all(), $validationRules);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => $validator->errors(),
                ], 422);
            }
            $return = $this->validateDataApplications($request, $server);
            if ($return instanceof JsonResponse) {
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
            if (mb_detect_encoding($dockerComposeRaw, 'UTF-8', true) === false) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => [
                        'docker_compose_raw' => 'The docker_compose_raw should be base64 encoded.',
                    ],
                ], 422);
            }
            $dockerCompose = base64_decode($request->docker_compose_raw);
            $dockerComposeRaw = Yaml::dump(Yaml::parse($dockerCompose), 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);

            $service = new Service;
            removeUnnecessaryFieldsFromRequest($request);
            $service->fill($request->only($allowedFields));

            $service->docker_compose_raw = $dockerComposeRaw;
            $service->environment_id = $environment->id;
            $service->server_id = $server->id;
            $service->destination_id = $destination->id;
            $service->destination_type = $destination->getMorphClass();
            if (isset($isContainerLabelEscapeEnabled)) {
                $service->is_container_label_escape_enabled = $isContainerLabelEscapeEnabled;
            }
            $service->save();

            $service->parse(isNew: true);

            // Apply service-specific application prerequisites
            applyServiceApplicationPrerequisites($service);

            if ($instantDeploy) {
                StartService::dispatch($service);
            }

            return response()->json(serializeApiResponse([
                'uuid' => data_get($service, 'uuid'),
                'domains' => data_get($service, 'domains'),
            ]))->setStatusCode(201);
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

        $this->authorize('view', $application);

        return response()->json($this->removeSensitiveData($application));
    }

    #[OA\Get(
        summary: 'Get application logs.',
        description: 'Get application logs by UUID.',
        path: '/applications/{uuid}/logs',
        operationId: 'get-application-logs-by-uuid',
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
                )
            ),
            new OA\Parameter(
                name: 'lines',
                in: 'query',
                description: 'Number of lines to show from the end of the logs.',
                required: false,
                schema: new OA\Schema(
                    type: 'integer',
                    format: 'int32',
                    default: 100,
                )
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Get application logs by UUID.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'logs' => ['type' => 'string'],
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
    public function logs_by_uuid(Request $request)
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

        $containers = getCurrentApplicationContainerStatus($application->destination->server, $application->id);

        if ($containers->count() == 0) {
            return response()->json([
                'message' => 'Application is not running.',
            ], 400);
        }

        $container = $containers->first();

        $status = getContainerStatus($application->destination->server, $container['Names']);
        if ($status !== 'running') {
            return response()->json([
                'message' => 'Application is not running.',
            ], 400);
        }

        $lines = $request->query->get('lines', 100) ?: 100;
        $logs = getContainerLogs($application->destination->server, $container['ID'], $lines);

        return response()->json([
            'logs' => $logs,
        ]);
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
        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $request->uuid)->first();

        if (! $application) {
            return response()->json([
                'message' => 'Application not found',
            ], 404);
        }

        $this->authorize('delete', $application);

        DeleteResourceJob::dispatch(
            resource: $application,
            deleteVolumes: $request->boolean('delete_volumes', true),
            deleteConnectedNetworks: $request->boolean('delete_connected_networks', true),
            deleteConfigurations: $request->boolean('delete_configurations', true),
            dockerCleanup: $request->boolean('docker_cleanup', true)
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
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                in: 'path',
                description: 'UUID of the application.',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                )
            ),
        ],
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
                            'domains' => ['type' => 'string', 'description' => 'The application URLs in a comma-separated list.'],
                            'git_commit_sha' => ['type' => 'string', 'description' => 'The git commit SHA.'],
                            'docker_registry_image_name' => ['type' => 'string', 'description' => 'The docker registry image name.'],
                            'docker_registry_image_tag' => ['type' => 'string', 'description' => 'The docker registry image tag.'],
                            'is_static' => ['type' => 'boolean', 'description' => 'The flag to indicate if the application is static.'],
                            'is_spa' => ['type' => 'boolean', 'description' => 'The flag to indicate if the application is a single-page application (SPA). Only relevant when is_static is true.'],
                            'is_auto_deploy_enabled' => ['type' => 'boolean', 'description' => 'The flag to indicate if auto-deploy is enabled on git push. Defaults to true.'],
                            'is_force_https_enabled' => ['type' => 'boolean', 'description' => 'The flag to indicate if HTTPS is forced. Defaults to true.'],
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
                            'dockerfile_location' => ['type' => 'string', 'description' => 'The Dockerfile location in the repository.'],
                            'docker_compose_location' => ['type' => 'string', 'description' => 'The Docker Compose location.'],
                            'docker_compose_custom_start_command' => ['type' => 'string', 'description' => 'The Docker Compose custom start command.'],
                            'docker_compose_custom_build_command' => ['type' => 'string', 'description' => 'The Docker Compose custom build command.'],
                            'docker_compose_domains' => [
                                'type' => 'array',
                                'description' => 'Array of URLs to be applied to containers of a dockercompose application.',
                                'items' => new OA\Schema(
                                    type: 'object',
                                    properties: [
                                        'name' => ['type' => 'string', 'description' => 'The service name as defined in docker-compose.'],
                                        'domain' => ['type' => 'string', 'description' => 'Comma-separated list of URLs (e.g. "https://app.coolify.io,https://app2.coolify.io")'],
                                    ],
                                ),
                            ],
                            'watch_paths' => ['type' => 'string', 'description' => 'The watch paths.'],
                            'use_build_server' => ['type' => 'boolean', 'nullable' => true, 'description' => 'Use build server.'],
                            'connect_to_docker_network' => ['type' => 'boolean', 'description' => 'The flag to connect the service to the predefined Docker network.'],
                            'force_domain_override' => ['type' => 'boolean', 'description' => 'Force domain usage even if conflicts are detected. Default is false.'],
                            'is_container_label_escape_enabled' => ['type' => 'boolean', 'default' => true, 'description' => 'Escape special characters in labels. By default, $ (and other chars) is escaped. So if you write $ in the labels, it will be saved as $$. If you want to use env variables inside the labels, turn this off.'],
                            'is_preserve_repository_enabled' => ['type' => 'boolean', 'description' => 'Preserve git repository during application update. If false, the existing repository will be removed and replaced with the new one. If true, the existing repository will be kept and the new one will be ignored. Default is false.'],
                        ],
                    )
                ),
            ]
        ),
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
                response: 409,
                description: 'Domain conflicts detected.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'message' => ['type' => 'string', 'example' => 'Domain conflicts detected. Use force_domain_override=true to proceed.'],
                                'warning' => ['type' => 'string', 'example' => 'Using the same domain for multiple resources can cause routing conflicts and unpredictable behavior.'],
                                'conflicts' => [
                                    'type' => 'array',
                                    'items' => new OA\Schema(
                                        type: 'object',
                                        properties: [
                                            'domain' => ['type' => 'string', 'example' => 'example.com'],
                                            'resource_name' => ['type' => 'string', 'example' => 'My Application'],
                                            'resource_uuid' => ['type' => 'string', 'nullable' => true, 'example' => 'abc123-def456'],
                                            'resource_type' => ['type' => 'string', 'enum' => ['application', 'service', 'instance'], 'example' => 'application'],
                                            'message' => ['type' => 'string', 'example' => 'Domain example.com is already in use by application \'My Application\''],
                                        ]
                                    ),
                                ],
                            ]
                        )
                    ),
                ]
            ),
        ]
    )]
    public function update_by_uuid(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $return = validateIncomingRequest($request);
        if ($return instanceof JsonResponse) {
            return $return;
        }

        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $request->uuid)->first();
        if (! $application) {
            return response()->json([
                'message' => 'Application not found',
            ], 404);
        }

        $this->authorize('update', $application);

        $server = $application->destination->server;
        $allowedFields = ['name', 'description', 'is_static', 'is_spa', 'is_auto_deploy_enabled', 'is_force_https_enabled', 'domains', 'git_repository', 'git_branch', 'git_commit_sha', 'docker_registry_image_name', 'docker_registry_image_tag', 'build_pack', 'static_image', 'install_command', 'build_command', 'start_command', 'ports_exposes', 'ports_mappings', 'custom_network_aliases', 'base_directory', 'publish_directory', 'health_check_enabled', 'health_check_type', 'health_check_command', 'health_check_path', 'health_check_port', 'health_check_host', 'health_check_method', 'health_check_return_code', 'health_check_scheme', 'health_check_response_text', 'health_check_interval', 'health_check_timeout', 'health_check_retries', 'health_check_start_period', 'limits_memory', 'limits_memory_swap', 'limits_memory_swappiness', 'limits_memory_reservation', 'limits_cpus', 'limits_cpuset', 'limits_cpu_shares', 'custom_labels', 'custom_docker_run_options', 'post_deployment_command', 'post_deployment_command_container', 'pre_deployment_command', 'pre_deployment_command_container', 'watch_paths', 'manual_webhook_secret_github', 'manual_webhook_secret_gitlab', 'manual_webhook_secret_bitbucket', 'manual_webhook_secret_gitea', 'dockerfile_location', 'dockerfile_target_build', 'docker_compose_location', 'docker_compose_custom_start_command', 'docker_compose_custom_build_command', 'docker_compose_domains', 'redirect', 'instant_deploy', 'use_build_server', 'custom_nginx_configuration', 'is_http_basic_auth_enabled', 'http_basic_auth_username', 'http_basic_auth_password', 'connect_to_docker_network', 'force_domain_override', 'is_container_label_escape_enabled', 'is_preserve_repository_enabled'];

        $validationRules = [
            'name' => 'string|max:255',
            'description' => 'string|nullable',
            'static_image' => 'string',
            'watch_paths' => 'string|nullable',
            'docker_compose_domains' => 'array|nullable',
            'docker_compose_domains.*' => 'array:name,domain',
            'docker_compose_domains.*.name' => 'string|required',
            'docker_compose_domains.*.domain' => 'string|nullable',
            'custom_nginx_configuration' => 'string|nullable',
            'is_http_basic_auth_enabled' => 'boolean|nullable',
            'http_basic_auth_username' => 'string',
            'http_basic_auth_password' => 'string',
        ];
        $validationRules = array_merge(sharedDataApplications(), $validationRules);
        $validationMessages = [
            'docker_compose_domains.*.array' => 'An item in the docker_compose_domains array has invalid fields. Only a name and domain field are supported.',
        ];
        $validator = Validator::make($request->all(), $validationRules, $validationMessages);

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
        if ($request->has('custom_nginx_configuration')) {
            if (! isBase64Encoded($request->custom_nginx_configuration)) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => [
                        'custom_nginx_configuration' => 'The custom_nginx_configuration should be base64 encoded.',
                    ],
                ], 422);
            }
            $customNginxConfiguration = base64_decode($request->custom_nginx_configuration);
            if (mb_detect_encoding($customNginxConfiguration, 'UTF-8', true) === false) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => [
                        'custom_nginx_configuration' => 'The custom_nginx_configuration should be base64 encoded.',
                    ],
                ], 422);
            }
        }
        $return = $this->validateDataApplications($request, $server);
        if ($return instanceof JsonResponse) {
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

        if ($request->has('is_http_basic_auth_enabled') && $request->is_http_basic_auth_enabled === true) {
            if (blank($application->http_basic_auth_username) || blank($application->http_basic_auth_password)) {
                $validationErrors = [];
                if (blank($request->http_basic_auth_username)) {
                    $validationErrors['http_basic_auth_username'] = 'The http_basic_auth_username is required.';
                }
                if (blank($request->http_basic_auth_password)) {
                    $validationErrors['http_basic_auth_password'] = 'The http_basic_auth_password is required.';
                }
                if (count($validationErrors) > 0) {
                    return response()->json([
                        'message' => 'Validation failed.',
                        'errors' => $validationErrors,
                    ], 422);
                }
            }
        }
        if ($request->has('is_http_basic_auth_enabled') && $application->is_container_label_readonly_enabled === false) {
            $application->custom_labels = str(implode('|coolify|', generateLabelsApplication($application)))->replace('|coolify|', "\n");
            $application->save();
        }

        // For dockercompose applications, domains (fqdn) field should not be used
        // Only docker_compose_domains should be used to set domains for individual services
        if ($application->build_pack === 'dockercompose' && $request->has('domains')) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => [
                    'domains' => 'The domains field cannot be used for dockercompose applications. Use docker_compose_domains instead to set domains for individual services.',
                ],
            ], 422);
        }

        $domains = $request->domains;
        $requestHasDomains = $request->has('domains');
        if ($requestHasDomains && $server->isProxyShouldRun()) {
            $uuid = $request->uuid;
            $urls = $request->domains;
            $urls = str($urls)->replaceStart(',', '')->replaceEnd(',', '')->trim();
            $errors = [];
            $urls = str($urls)->trim()->explode(',')->map(function ($url) use (&$errors) {
                $url = trim($url);

                // If "domains" is empty clear all URLs from the fqdn column
                if (blank($url)) {
                    return null;
                }

                if (! filter_var($url, FILTER_VALIDATE_URL)) {
                    $errors[] = 'Invalid URL: '.$url;

                    return $url;
                }
                $scheme = parse_url($url, PHP_URL_SCHEME) ?? '';
                if (! in_array(strtolower($scheme), ['http', 'https'])) {
                    $errors[] = "Invalid URL scheme: {$scheme} for URL: {$url}. Only http and https are supported.";
                }

                return str($url)->lower();
            });

            if (count($errors) > 0) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => $errors,
                ], 422);
            }
            // Check for domain conflicts
            $result = checkIfDomainIsAlreadyUsedViaAPI($urls, $teamId, $uuid);
            if (isset($result['error'])) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => ['domains' => $result['error']],
                ], 422);
            }

            // If there are conflicts and force is not enabled, return warning
            if ($result['hasConflicts'] && ! $request->boolean('force_domain_override')) {
                return response()->json([
                    'message' => 'Domain conflicts detected. Use force_domain_override=true to proceed.',
                    'conflicts' => $result['conflicts'],
                    'warning' => 'Using the same domain for multiple resources can cause routing conflicts and unpredictable behavior.',
                ], 409);
            }
        }

        $dockerComposeDomainsJson = collect();
        if ($request->has('docker_compose_domains')) {
            if (empty($application->docker_compose_raw)) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => [
                        'docker_compose_domains' => 'Cannot set docker_compose_domains without docker_compose_raw. Reload the compose file from the git repository first.',
                    ],
                ], 422);
            }

            $dockerComposeDomains = collect($request->docker_compose_domains);

            // Collect all URLs from all docker_compose_domains items
            $urls = $dockerComposeDomains->flatMap(function ($item) {
                $domainValue = data_get($item, 'domain');
                if (blank($domainValue)) {
                    return [];
                }

                return str($domainValue)->replaceStart(',', '')->replaceEnd(',', '')->trim()->explode(',')->map(fn ($url) => trim($url))->filter();
            });

            $errors = [];
            $urls = $urls->map(function ($url) use (&$errors) {
                if (! filter_var($url, FILTER_VALIDATE_URL)) {
                    $errors[] = "Invalid URL: {$url}";

                    return $url;
                }
                $scheme = parse_url($url, PHP_URL_SCHEME) ?? '';
                if (! in_array(strtolower($scheme), ['http', 'https'])) {
                    $errors[] = "Invalid URL scheme: {$scheme} for URL: {$url}. Only http and https are supported.";
                }

                return $url;
            });

            $duplicates = $urls->duplicates()->unique()->values();
            if ($duplicates->isNotEmpty() && ! $request->boolean('force_domain_override')) {
                $errors[] = 'The current request contains conflicting URLs: '.implode(', ', $duplicates->toArray()).' Use force_domain_override=true to proceed.';
            }

            if (count($errors) > 0) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => ['docker_compose_domains' => $errors],
                ], 422);
            }

            // Check for domain conflicts
            if ($urls->isNotEmpty()) {
                $result = checkIfDomainIsAlreadyUsedViaAPI($urls, $teamId, $request->uuid);
                if (isset($result['error'])) {
                    return response()->json([
                        'message' => 'Validation failed.',
                        'errors' => ['docker_compose_domains' => $result['error']],
                    ], 422);
                }

                if ($result['hasConflicts'] && ! $request->boolean('force_domain_override')) {
                    return response()->json([
                        'message' => 'Domain conflicts detected. Use force_domain_override=true to proceed.',
                        'conflicts' => $result['conflicts'],
                        'warning' => 'Using the same domain for multiple resources can cause routing conflicts and unpredictable behavior.',
                    ], 409);
                }
            }

            $yaml = Yaml::parse($application->docker_compose_raw);
            $services = data_get($yaml, 'services', []);
            $dockerComposeDomains->each(function ($domain) use ($services, $dockerComposeDomainsJson) {
                $name = data_get($domain, 'name');
                if ($name && is_array($services) && isset($services[$name])) {
                    $dockerComposeDomainsJson->put($name, ['domain' => data_get($domain, 'domain')]);
                }
            });
            $request->offsetUnset('docker_compose_domains');
        }
        $instantDeploy = $request->instant_deploy;
        $isStatic = $request->is_static;
        $isSpa = $request->is_spa;
        $isAutoDeployEnabled = $request->is_auto_deploy_enabled;
        $isForceHttpsEnabled = $request->is_force_https_enabled;
        $connectToDockerNetwork = $request->connect_to_docker_network;
        $useBuildServer = $request->use_build_server;
        $isContainerLabelEscapeEnabled = $request->boolean('is_container_label_escape_enabled');
        $isPreserveRepositoryEnabled = $request->boolean('is_preserve_repository_enabled');
        if (isset($useBuildServer)) {
            $application->settings->is_build_server_enabled = $useBuildServer;
            $application->settings->save();
        }

        if (isset($isStatic)) {
            $application->settings->is_static = $isStatic;
            $application->settings->save();
        }

        if (isset($isSpa)) {
            $application->settings->is_spa = $isSpa;
            $application->settings->save();
        }

        if (isset($isAutoDeployEnabled)) {
            $application->settings->is_auto_deploy_enabled = $isAutoDeployEnabled;
            $application->settings->save();
        }

        if (isset($isForceHttpsEnabled)) {
            $application->settings->is_force_https_enabled = $isForceHttpsEnabled;
            $application->settings->save();
        }

        if (isset($connectToDockerNetwork)) {
            $application->settings->connect_to_docker_network = $connectToDockerNetwork;
            $application->settings->save();
        }

        if ($request->has('is_container_label_escape_enabled')) {
            $application->settings->is_container_label_escape_enabled = $isContainerLabelEscapeEnabled;
            $application->settings->save();
        }
        if ($request->has('is_preserve_repository_enabled')) {
            $application->settings->is_preserve_repository_enabled = $isPreserveRepositoryEnabled;
            $application->settings->save();
        }
        removeUnnecessaryFieldsFromRequest($request);

        $data = $request->only($allowedFields);
        if ($requestHasDomains && $server->isProxyShouldRun()) {
            data_set($data, 'fqdn', $domains);
        }

        if ($dockerComposeDomainsJson->count() > 0) {
            data_set($data, 'docker_compose_domains', json_encode($dockerComposeDomainsJson));
        }
        $application->fill($data);
        if ($application->settings->is_container_label_readonly_enabled && $requestHasDomains && $server->isProxyShouldRun()) {
            $application->custom_labels = str(implode('|coolify|', generateLabelsApplication($application)))->replace('|coolify|', "\n");
        }
        $application->save();

        if ($instantDeploy) {
            $deployment_uuid = new Cuid2;

            $result = queue_application_deployment(
                application: $application,
                deployment_uuid: $deployment_uuid,
                is_api: true,
            );
            if ($result['status'] === 'skipped') {
                return response()->json([
                    'message' => $result['message'],
                ], 200);
            }
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
        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $request->uuid)->first();

        if (! $application) {
            return response()->json([
                'message' => 'Application not found',
            ], 404);
        }

        $this->authorize('view', $application);

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

            return $this->removeSensitiveData($env);
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
        ]
    )]
    public function update_env_by_uuid(Request $request)
    {
        $allowedFields = ['key', 'value', 'is_preview', 'is_literal', 'is_multiline', 'is_shown_once', 'is_runtime', 'is_buildtime', 'comment'];
        $teamId = getTeamIdFromToken();

        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $return = validateIncomingRequest($request);
        if ($return instanceof JsonResponse) {
            return $return;
        }
        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $request->route('uuid'))->first();

        if (! $application) {
            return response()->json([
                'message' => 'Application not found',
            ], 404);
        }

        $this->authorize('manageEnvironment', $application);

        $validator = customApiValidator($request->all(), [
            'key' => 'string|required',
            'value' => 'string|nullable',
            'is_preview' => 'boolean',
            'is_literal' => 'boolean',
            'is_multiline' => 'boolean',
            'is_shown_once' => 'boolean',
            'is_runtime' => 'boolean',
            'is_buildtime' => 'boolean',
            'comment' => 'string|nullable|max:256',
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
        $is_literal = $request->is_literal ?? false;
        $key = str($request->key)->trim()->replace(' ', '_')->value;
        if ($is_preview) {
            $env = $application->environment_variables_preview->where('key', $key)->first();
            if ($env) {
                $env->value = $request->value;
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
                if ($request->has('is_runtime') && $env->is_runtime != $request->is_runtime) {
                    $env->is_runtime = $request->is_runtime;
                }
                if ($request->has('is_buildtime') && $env->is_buildtime != $request->is_buildtime) {
                    $env->is_buildtime = $request->is_buildtime;
                }
                if ($request->has('comment') && $env->comment != $request->comment) {
                    $env->comment = $request->comment;
                }
                $env->save();

                return response()->json($this->removeSensitiveData($env))->setStatusCode(201);
            } else {
                return response()->json([
                    'message' => 'Environment variable not found.',
                ], 404);
            }
        } else {
            $env = $application->environment_variables->where('key', $key)->first();
            if ($env) {
                $env->value = $request->value;
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
                if ($request->has('is_runtime') && $env->is_runtime != $request->is_runtime) {
                    $env->is_runtime = $request->is_runtime;
                }
                if ($request->has('is_buildtime') && $env->is_buildtime != $request->is_buildtime) {
                    $env->is_buildtime = $request->is_buildtime;
                }
                if ($request->has('comment') && $env->comment != $request->comment) {
                    $env->comment = $request->comment;
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
        ]
    )]
    public function create_bulk_envs(Request $request)
    {
        $teamId = getTeamIdFromToken();

        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $return = validateIncomingRequest($request);
        if ($return instanceof JsonResponse) {
            return $return;
        }
        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $request->route('uuid'))->first();

        if (! $application) {
            return response()->json([
                'message' => 'Application not found',
            ], 404);
        }

        $this->authorize('manageEnvironment', $application);

        $bulk_data = $request->get('data');
        if (! $bulk_data) {
            return response()->json([
                'message' => 'Bulk data is required.',
            ], 400);
        }
        $bulk_data = collect($bulk_data)->map(function ($item) {
            return collect($item)->only(['key', 'value', 'is_preview', 'is_literal', 'is_multiline', 'is_shown_once', 'is_runtime', 'is_buildtime', 'comment']);
        });
        $returnedEnvs = collect();
        foreach ($bulk_data as $item) {
            $validator = customApiValidator($item, [
                'key' => 'string|required',
                'value' => 'string|nullable',
                'is_preview' => 'boolean',
                'is_literal' => 'boolean',
                'is_multiline' => 'boolean',
                'is_shown_once' => 'boolean',
                'is_runtime' => 'boolean',
                'is_buildtime' => 'boolean',
                'comment' => 'string|nullable|max:256',
            ]);
            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => $validator->errors(),
                ], 422);
            }
            $is_preview = $item->get('is_preview') ?? false;
            $is_literal = $item->get('is_literal') ?? false;
            $is_multi_line = $item->get('is_multiline') ?? false;
            $is_shown_once = $item->get('is_shown_once') ?? false;
            $key = str($item->get('key'))->trim()->replace(' ', '_')->value;
            if ($is_preview) {
                $env = $application->environment_variables_preview->where('key', $key)->first();
                if ($env) {
                    $env->value = $item->get('value');

                    if ($env->is_literal != $is_literal) {
                        $env->is_literal = $is_literal;
                    }
                    if ($env->is_multiline != $item->get('is_multiline')) {
                        $env->is_multiline = $item->get('is_multiline');
                    }
                    if ($env->is_shown_once != $item->get('is_shown_once')) {
                        $env->is_shown_once = $item->get('is_shown_once');
                    }
                    if ($item->has('is_runtime') && $env->is_runtime != $item->get('is_runtime')) {
                        $env->is_runtime = $item->get('is_runtime');
                    }
                    if ($item->has('is_buildtime') && $env->is_buildtime != $item->get('is_buildtime')) {
                        $env->is_buildtime = $item->get('is_buildtime');
                    }
                    if ($item->has('comment') && $env->comment != $item->get('comment')) {
                        $env->comment = $item->get('comment');
                    }
                    $env->save();
                } else {
                    $env = $application->environment_variables()->create([
                        'key' => $item->get('key'),
                        'value' => $item->get('value'),
                        'is_preview' => $is_preview,
                        'is_literal' => $is_literal,
                        'is_multiline' => $is_multi_line,
                        'is_shown_once' => $is_shown_once,
                        'is_runtime' => $item->get('is_runtime', true),
                        'is_buildtime' => $item->get('is_buildtime', true),
                        'comment' => $item->get('comment'),
                        'resourceable_type' => get_class($application),
                        'resourceable_id' => $application->id,
                    ]);
                }
            } else {
                $env = $application->environment_variables->where('key', $key)->first();
                if ($env) {
                    $env->value = $item->get('value');
                    if ($env->is_literal != $is_literal) {
                        $env->is_literal = $is_literal;
                    }
                    if ($env->is_multiline != $item->get('is_multiline')) {
                        $env->is_multiline = $item->get('is_multiline');
                    }
                    if ($env->is_shown_once != $item->get('is_shown_once')) {
                        $env->is_shown_once = $item->get('is_shown_once');
                    }
                    if ($item->has('is_runtime') && $env->is_runtime != $item->get('is_runtime')) {
                        $env->is_runtime = $item->get('is_runtime');
                    }
                    if ($item->has('is_buildtime') && $env->is_buildtime != $item->get('is_buildtime')) {
                        $env->is_buildtime = $item->get('is_buildtime');
                    }
                    if ($item->has('comment') && $env->comment != $item->get('comment')) {
                        $env->comment = $item->get('comment');
                    }
                    $env->save();
                } else {
                    $env = $application->environment_variables()->create([
                        'key' => $item->get('key'),
                        'value' => $item->get('value'),
                        'is_preview' => $is_preview,
                        'is_literal' => $is_literal,
                        'is_multiline' => $is_multi_line,
                        'is_shown_once' => $is_shown_once,
                        'is_runtime' => $item->get('is_runtime', true),
                        'is_buildtime' => $item->get('is_buildtime', true),
                        'comment' => $item->get('comment'),
                        'resourceable_type' => get_class($application),
                        'resourceable_id' => $application->id,
                    ]);
                }
            }
            $returnedEnvs->push($this->removeSensitiveData($env));
        }

        return response()->json($returnedEnvs)->setStatusCode(201);
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
        ]
    )]
    public function create_env(Request $request)
    {
        $allowedFields = ['key', 'value', 'is_preview', 'is_literal', 'is_multiline', 'is_shown_once', 'is_runtime', 'is_buildtime', 'comment'];
        $teamId = getTeamIdFromToken();

        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $request->route('uuid'))->first();

        if (! $application) {
            return response()->json([
                'message' => 'Application not found',
            ], 404);
        }

        $this->authorize('manageEnvironment', $application);

        $validator = customApiValidator($request->all(), [
            'key' => 'string|required',
            'value' => 'string|nullable',
            'is_preview' => 'boolean',
            'is_literal' => 'boolean',
            'is_multiline' => 'boolean',
            'is_shown_once' => 'boolean',
            'is_runtime' => 'boolean',
            'is_buildtime' => 'boolean',
            'comment' => 'string|nullable|max:256',
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
        $key = str($request->key)->trim()->replace(' ', '_')->value;

        if ($is_preview) {
            $env = $application->environment_variables_preview->where('key', $key)->first();
            if ($env) {
                return response()->json([
                    'message' => 'Environment variable already exists. Use PATCH request to update it.',
                ], 409);
            } else {
                $env = $application->environment_variables()->create([
                    'key' => $request->key,
                    'value' => $request->value,
                    'is_preview' => $request->is_preview ?? false,
                    'is_literal' => $request->is_literal ?? false,
                    'is_multiline' => $request->is_multiline ?? false,
                    'is_shown_once' => $request->is_shown_once ?? false,
                    'is_runtime' => $request->is_runtime ?? true,
                    'is_buildtime' => $request->is_buildtime ?? true,
                    'comment' => $request->comment ?? null,
                    'resourceable_type' => get_class($application),
                    'resourceable_id' => $application->id,
                ]);

                return response()->json([
                    'uuid' => $env->uuid,
                ])->setStatusCode(201);
            }
        } else {
            $env = $application->environment_variables->where('key', $key)->first();
            if ($env) {
                return response()->json([
                    'message' => 'Environment variable already exists. Use PATCH request to update it.',
                ], 409);
            } else {
                $env = $application->environment_variables()->create([
                    'key' => $request->key,
                    'value' => $request->value,
                    'is_preview' => $request->is_preview ?? false,
                    'is_literal' => $request->is_literal ?? false,
                    'is_multiline' => $request->is_multiline ?? false,
                    'is_shown_once' => $request->is_shown_once ?? false,
                    'is_runtime' => $request->is_runtime ?? true,
                    'is_buildtime' => $request->is_buildtime ?? true,
                    'comment' => $request->comment ?? null,
                    'resourceable_type' => get_class($application),
                    'resourceable_id' => $application->id,
                ]);

                return response()->json([
                    'uuid' => $env->uuid,
                ])->setStatusCode(201);
            }
        }
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
        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $request->route('uuid'))->first();

        if (! $application) {
            return response()->json([
                'message' => 'Application not found.',
            ], 404);
        }

        $this->authorize('manageEnvironment', $application);

        $found_env = EnvironmentVariable::where('uuid', $request->route('env_uuid'))
            ->where('resourceable_type', Application::class)
            ->where('resourceable_id', $application->id)
            ->first();
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
        $force = $request->boolean('force', false);
        $instant_deploy = $request->boolean('instant_deploy', false);
        $uuid = $request->route('uuid');
        if (! $uuid) {
            return response()->json(['message' => 'UUID is required.'], 400);
        }
        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $request->uuid)->first();
        if (! $application) {
            return response()->json(['message' => 'Application not found.'], 404);
        }

        $this->authorize('deploy', $application);

        $deployment_uuid = new Cuid2;

        $result = queue_application_deployment(
            application: $application,
            deployment_uuid: $deployment_uuid,
            force_rebuild: $force,
            is_api: true,
            no_questions_asked: $instant_deploy
        );
        if ($result['status'] === 'skipped') {
            return response()->json(
                [
                    'message' => $result['message'],
                ],
                200
            );
        }

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
        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $request->uuid)->first();
        if (! $application) {
            return response()->json(['message' => 'Application not found.'], 404);
        }

        $this->authorize('deploy', $application);

        $dockerCleanup = $request->boolean('docker_cleanup', true);
        StopApplication::dispatch($application, false, $dockerCleanup);

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
        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $request->uuid)->first();
        if (! $application) {
            return response()->json(['message' => 'Application not found.'], 404);
        }

        $this->authorize('deploy', $application);

        $deployment_uuid = new Cuid2;

        $result = queue_application_deployment(
            application: $application,
            deployment_uuid: $deployment_uuid,
            restart_only: true,
            is_api: true,
        );
        if ($result['status'] === 'skipped') {
            return response()->json([
                'message' => $result['message'],
            ], 200);
        }

        return response()->json(
            [
                'message' => 'Restart request queued.',
                'deployment_uuid' => $deployment_uuid->toString(),
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
            if (mb_detect_encoding($customLabels, 'UTF-8', true) === false) {
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
            $urls = $request->domains;
            $urls = str($urls)->replaceEnd(',', '')->trim();
            $urls = str($urls)->replaceStart(',', '')->trim();
            $errors = [];
            $urls = str($urls)->trim()->explode(',')->map(function ($url) use (&$errors) {
                $url = trim($url);

                // If "domains" is empty clear all URLs from the fqdn column
                if (blank($url)) {
                    return null;
                }

                if (! filter_var($url, FILTER_VALIDATE_URL)) {
                    $errors[] = 'Invalid URL: '.$url;

                    return str($url)->lower();
                }
                $scheme = parse_url($url, PHP_URL_SCHEME) ?? '';
                if (! in_array(strtolower($scheme), ['http', 'https'])) {
                    $errors[] = "Invalid URL scheme: {$scheme} for URL: {$url}. Only http and https are supported.";
                }

                return str($url)->lower();
            });
            if (count($errors) > 0) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => $errors,
                ], 422);
            }
            // Check for domain conflicts
            $result = checkIfDomainIsAlreadyUsedViaAPI($urls, $teamId, $uuid);
            if (isset($result['error'])) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => ['domains' => $result['error']],
                ], 422);
            }

            // If there are conflicts and force is not enabled, return warning
            if ($result['hasConflicts'] && ! $request->boolean('force_domain_override')) {
                return response()->json([
                    'message' => 'Domain conflicts detected. Use force_domain_override=true to proceed.',
                    'conflicts' => $result['conflicts'],
                    'warning' => 'Using the same domain for multiple resources can cause routing conflicts and unpredictable behavior.',
                ], 409);
            }
        }
    }

    #[OA\Get(
        summary: 'List Storages',
        description: 'List all persistent storages and file storages by application UUID.',
        path: '/applications/{uuid}/storages',
        operationId: 'list-storages-by-application-uuid',
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
                )
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'All storages by application UUID.',
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
        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $request->uuid)->first();

        if (! $application) {
            return response()->json([
                'message' => 'Application not found',
            ], 404);
        }

        $this->authorize('view', $application);

        $persistentStorages = $application->persistentStorages->sortBy('id')->values();
        $fileStorages = $application->fileStorages->sortBy('id')->values();

        return response()->json([
            'persistent_storages' => $persistentStorages,
            'file_storages' => $fileStorages,
        ]);
    }

    #[OA\Patch(
        summary: 'Update Storage',
        description: 'Update a persistent storage or file storage by application UUID.',
        path: '/applications/{uuid}/storages',
        operationId: 'update-storage-by-application-uuid',
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
        if ($return instanceof JsonResponse) {
            return $return;
        }

        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $request->route('uuid'))->first();

        if (! $application) {
            return response()->json([
                'message' => 'Application not found',
            ], 404);
        }

        $this->authorize('update', $application);

        $validator = customApiValidator($request->all(), [
            'uuid' => 'string',
            'id' => 'integer',
            'type' => 'required|string|in:persistent,file',
            'is_preview_suffix_enabled' => 'boolean',
            'name' => ['string', 'regex:'.ValidationPatterns::VOLUME_NAME_PATTERN],
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
            $storage = $application->persistentStorages->where($lookupField, $lookupValue)->first();
        } else {
            $storage = $application->fileStorages->where($lookupField, $lookupValue)->first();
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

    #[OA\Post(
        summary: 'Create Storage',
        description: 'Create a persistent storage or file storage for an application.',
        path: '/applications/{uuid}/storages',
        operationId: 'create-storage-by-application-uuid',
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

        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $request->uuid)->first();
        if (! $application) {
            return response()->json(['message' => 'Application not found.'], 404);
        }

        $this->authorize('update', $application);

        $validator = customApiValidator($request->all(), [
            'type' => 'required|string|in:persistent,file',
            'name' => ['string', 'regex:'.ValidationPatterns::VOLUME_NAME_PATTERN],
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
                'name' => $application->uuid.'-'.$request->name,
                'mount_path' => $request->mount_path,
                'host_path' => $request->host_path,
                'resource_id' => $application->id,
                'resource_type' => $application->getMorphClass(),
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
                'resource_id' => $application->id,
                'resource_type' => get_class($application),
            ]);
        } else {
            $mountPath = str($request->mount_path)->trim()->start('/')->value();

            validateShellSafePath($mountPath, 'file storage path');

            $fsPath = application_configuration_dir().'/'.$application->uuid.$mountPath;

            $storage = LocalFileVolume::create([
                'fs_path' => $fsPath,
                'mount_path' => $mountPath,
                'content' => $request->content,
                'is_directory' => false,
                'resource_id' => $application->id,
                'resource_type' => get_class($application),
            ]);
        }

        return response()->json($storage, 201);
    }

    #[OA\Delete(
        summary: 'Delete Storage',
        description: 'Delete a persistent storage or file storage by application UUID.',
        path: '/applications/{uuid}/storages/{storage_uuid}',
        operationId: 'delete-storage-by-application-uuid',
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

        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $request->uuid)->first();
        if (! $application) {
            return response()->json(['message' => 'Application not found.'], 404);
        }

        $this->authorize('update', $application);

        $storageUuid = $request->route('storage_uuid');

        $storage = $application->persistentStorages->where('uuid', $storageUuid)->first();
        if (! $storage) {
            $storage = $application->fileStorages->where('uuid', $storageUuid)->first();
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
