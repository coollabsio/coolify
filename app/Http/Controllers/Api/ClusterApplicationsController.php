<?php

namespace App\Http\Controllers\Api;

use App\Actions\Node\Build\CreateClusterGitApplication;
use App\Actions\Node\Build\CreateClusterInlineDockerfileWorkload;
use App\Actions\Node\CreateClusterDockerImageWorkload;
use App\Actions\Node\RollbackNodeWorkload;
use App\Actions\Node\UpdateNodeWorkloadSettings;
use App\Enums\NodeOperationStatus;
use App\Enums\NodeWorkloadAction;
use App\Enums\NodeWorkloadSourceType;
use App\Http\Controllers\Api\Concerns\HandlesClusterResourcesApi;
use App\Http\Controllers\Controller;
use App\Models\NodeOperation;
use App\Models\NodeWorkload;
use App\Models\NodeWorkloadRevision;
use App\Rules\ValidGitBranch;
use App\Services\DockerImageParser;
use App\Support\ValidationPatterns;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use OpenApi\Attributes as OA;
use RuntimeException;

/**
 * Cluster applications (decision 0029): list, show, create, update, delete, deploy, the lifecycle
 * actions, revisions, and rollback (decision 0017). Each write calls the action of the
 * application page. A revision also stores the environment variables and secrets of its
 * deployment; this API never returns them.
 */
class ClusterApplicationsController extends Controller
{
    use HandlesClusterResourcesApi;

    private const REVISION_LIMIT = 50;

    private const PATH_RULES = ['nullable', 'string', 'max:512', 'regex:/^\/?[A-Za-z0-9._\/-]*$/', 'not_regex:/(^|\/)\.\.(\/|$)/'];

    #[OA\Get(
        summary: 'List',
        description: 'List the cluster applications that the token can see. Stack services, databases, and previews are not in this list.',
        path: '/cluster-applications',
        operationId: 'list-cluster-applications',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Applications'],
        responses: [
            new OA\Response(response: 200, description: 'The cluster applications.', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/ClusterApplication'))),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
        ]
    )]
    public function index(): JsonResponse
    {
        $workloads = $this->clusterWorkloads('application')->with(['nodes', 'project', 'environment'])->orderBy('name')->get();

        return response()->json($workloads->map(fn (NodeWorkload $workload): array => $this->workloadResponse($workload))->values());
    }

    #[OA\Get(
        summary: 'Get',
        description: 'Get a cluster application by UUID.',
        path: '/cluster-applications/{uuid}',
        operationId: 'get-cluster-application-by-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Applications'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster application.', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The cluster application.', content: new OA\JsonContent(ref: '#/components/schemas/ClusterApplication')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function show(Request $request): JsonResponse
    {
        $workload = $this->clusterWorkload($request, 'application');
        if ($workload === null) {
            return response()->json(['message' => 'Cluster application not found.'], 404);
        }
        $this->authorize('view', $workload);

        return response()->json($this->workloadResponse($workload));
    }

    #[OA\Post(
        summary: 'Create (Docker Image)',
        description: 'Create a cluster application from a Docker image. Set `cluster_uuid` for automatic placement, or `server_uuid` for one server of the cluster. Like v4, it does not deploy unless `instant_deploy` is true.',
        path: '/cluster-applications/docker-image',
        operationId: 'create-cluster-application-docker-image',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Applications'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            type: 'object',
            required: ['project_uuid', 'docker_registry_image_name'],
            properties: [
                'project_uuid' => new OA\Property(property: 'project_uuid', type: 'string'),
                'environment_uuid' => new OA\Property(property: 'environment_uuid', type: 'string', description: 'Or `environment_name`.'),
                'environment_name' => new OA\Property(property: 'environment_name', type: 'string'),
                'cluster_uuid' => new OA\Property(property: 'cluster_uuid', type: 'string', description: 'Automatic placement in this cluster.'),
                'server_uuid' => new OA\Property(property: 'server_uuid', type: 'string', description: 'One server of a cluster.'),
                'docker_registry_image_name' => new OA\Property(property: 'docker_registry_image_name', type: 'string', description: 'The image, for example `nginx` or `ghcr.io/acme/api:1.2`.'),
                'docker_registry_image_tag' => new OA\Property(property: 'docker_registry_image_tag', type: 'string', description: 'The tag, or a sha256 digest. Default `latest`.'),
                'name' => new OA\Property(property: 'name', type: 'string'),
                'description' => new OA\Property(property: 'description', type: 'string'),
                'instant_deploy' => new OA\Property(property: 'instant_deploy', type: 'boolean'),
            ],
        )),
        responses: [
            new OA\Response(response: 201, description: 'The cluster application is created.', content: new OA\JsonContent(ref: '#/components/schemas/ClusterResourceCreated')),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'The user may not create or place the resource on this environment or server, or may not build on a builder server.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function createDockerImage(Request $request): JsonResponse
    {
        $invalid = $this->validateRequest($request, [
            ...$this->clusterTargetRules(),
            'docker_registry_image_name' => ValidationPatterns::dockerImageNameRules(required: true),
            'docker_registry_image_tag' => ValidationPatterns::dockerImageTagRules(),
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
        $tag = $request->filled('docker_registry_image_tag') ? trim((string) $request->input('docker_registry_image_tag')) : null;
        $image = trim((string) $request->input('docker_registry_image_name')).($tag === null ? '' : (preg_match('/^[a-f0-9]{64}$/i', $tag) === 1 ? '@sha256:' : ':').$tag);

        return $this->runClusterAction(function () use ($request, $project, $environment, $cluster, $node, $image): JsonResponse {
            $workload = CreateClusterDockerImageWorkload::run($project, $environment, $cluster, $image, $request->user(), $node, $request->input('name'))['workload'];

            return $this->created($request, $workload);
        });
    }

    #[OA\Post(
        summary: 'Create (Public Git)',
        description: 'Create a cluster application from a public Git repository. Coolify builds it on a builder server of the cluster with Railpack, the Dockerfile of the repository, or as Static files. Use `/cluster-stacks/git` for a Docker Compose file.',
        path: '/cluster-applications/public',
        operationId: 'create-cluster-application-public',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Applications'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ClusterGitApplicationRequest')),
        responses: [
            new OA\Response(response: 201, description: 'The cluster application is created.', content: new OA\JsonContent(ref: '#/components/schemas/ClusterResourceCreated')),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'The user may not create or place the resource on this environment or server, or may not build on a builder server.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function createPublic(Request $request): JsonResponse
    {
        return $this->createFromGit($request, 'public');
    }

    #[OA\Post(
        summary: 'Create (Private - GitHub App)',
        description: 'Create a cluster application from a private repository through a GitHub App of the team. `git_repository` is `owner/repository`.',
        path: '/cluster-applications/private-github-app',
        operationId: 'create-cluster-application-private-github-app',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Applications'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ClusterGitApplicationRequest')),
        responses: [
            new OA\Response(response: 201, description: 'The cluster application is created.', content: new OA\JsonContent(ref: '#/components/schemas/ClusterResourceCreated')),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'The user may not create or place the resource on this environment or server, or may not build on a builder server.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function createPrivateGithubApp(Request $request): JsonResponse
    {
        return $this->createFromGit($request, 'github-app');
    }

    #[OA\Post(
        summary: 'Create (Private - Deploy Key)',
        description: 'Create a cluster application from a private repository with a deploy key (a private key of the team).',
        path: '/cluster-applications/private-deploy-key',
        operationId: 'create-cluster-application-private-deploy-key',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Applications'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ClusterGitApplicationRequest')),
        responses: [
            new OA\Response(response: 201, description: 'The cluster application is created.', content: new OA\JsonContent(ref: '#/components/schemas/ClusterResourceCreated')),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'The user may not create or place the resource on this environment or server, or may not build on a builder server.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function createPrivateDeployKey(Request $request): JsonResponse
    {
        return $this->createFromGit($request, 'deploy-key');
    }

    #[OA\Post(
        summary: 'Create (Dockerfile)',
        description: 'Create a cluster application from a Dockerfile without Git. A builder server of the cluster builds it.',
        path: '/cluster-applications/dockerfile',
        operationId: 'create-cluster-application-dockerfile',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Applications'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            type: 'object',
            required: ['project_uuid', 'dockerfile'],
            properties: [
                'project_uuid' => new OA\Property(property: 'project_uuid', type: 'string'),
                'environment_uuid' => new OA\Property(property: 'environment_uuid', type: 'string'),
                'environment_name' => new OA\Property(property: 'environment_name', type: 'string'),
                'cluster_uuid' => new OA\Property(property: 'cluster_uuid', type: 'string'),
                'server_uuid' => new OA\Property(property: 'server_uuid', type: 'string'),
                'dockerfile' => new OA\Property(property: 'dockerfile', type: 'string', description: 'The Dockerfile, as plain text or base64.'),
                'name' => new OA\Property(property: 'name', type: 'string'),
                'description' => new OA\Property(property: 'description', type: 'string'),
                'instant_deploy' => new OA\Property(property: 'instant_deploy', type: 'boolean'),
            ],
        )),
        responses: [
            new OA\Response(response: 201, description: 'The cluster application is created.', content: new OA\JsonContent(ref: '#/components/schemas/ClusterResourceCreated')),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'The user may not create or place the resource on this environment or server, or may not build on a builder server.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function createDockerfile(Request $request): JsonResponse
    {
        $dockerfile = (string) $request->input('dockerfile');
        $decoded = base64_decode($dockerfile, true);
        if ($decoded !== false && mb_check_encoding($decoded, 'UTF-8') && base64_encode($decoded) === preg_replace('/\s/', '', $dockerfile)) {
            $request->merge(['dockerfile' => $decoded]);
        }
        $invalid = $this->validateRequest($request, [
            ...$this->clusterTargetRules(),
            'dockerfile' => CreateClusterInlineDockerfileWorkload::dockerfileRules(),
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

        return $this->runClusterAction(function () use ($request, $project, $environment, $cluster, $node): JsonResponse {
            $workload = CreateClusterInlineDockerfileWorkload::run($project, $environment, $cluster, (string) $request->input('dockerfile'), $request->user(), $node, $request->input('name'));

            return $this->created($request, $workload);
        });
    }

    #[OA\Patch(
        summary: 'Update',
        description: 'Update a cluster application. Only the fields in the request change. An image application uses `docker_registry_image_name` and `docker_registry_image_tag` for its image. An application that Coolify builds uses them for the registry that the build pushes to. A running application applies a new image or command at its next deploy.',
        path: '/cluster-applications/{uuid}',
        operationId: 'update-cluster-application-by-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Applications'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster application.', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            type: 'object',
            properties: [
                'name' => new OA\Property(property: 'name', type: 'string'),
                'description' => new OA\Property(property: 'description', type: 'string'),
                'docker_registry_image_name' => new OA\Property(property: 'docker_registry_image_name', type: 'string'),
                'docker_registry_image_tag' => new OA\Property(property: 'docker_registry_image_tag', type: 'string'),
                'registry_credential_uuid' => new OA\Property(property: 'registry_credential_uuid', type: 'string', description: 'A registry credential of the team, or empty for none.'),
                'start_command' => new OA\Property(property: 'start_command', type: 'string', description: 'Words split at spaces, with "" quotes.'),
                'git_branch' => new OA\Property(property: 'git_branch', type: 'string'),
                'build_pack' => new OA\Property(property: 'build_pack', type: 'string', enum: ['railpack', 'dockerfile', 'static']),
                'base_directory' => new OA\Property(property: 'base_directory', type: 'string'),
                'dockerfile_location' => new OA\Property(property: 'dockerfile_location', type: 'string'),
                'publish_directory' => new OA\Property(property: 'publish_directory', type: 'string'),
                'dockerfile' => new OA\Property(property: 'dockerfile', type: 'string'),
                'is_auto_deploy_enabled' => new OA\Property(property: 'is_auto_deploy_enabled', type: 'boolean'),
                'is_auto_cancel_queued_deployments_enabled' => new OA\Property(property: 'is_auto_cancel_queued_deployments_enabled', type: 'boolean'),
                'is_auto_cancel_building_deployments_enabled' => new OA\Property(property: 'is_auto_cancel_building_deployments_enabled', type: 'boolean'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'The updated cluster application.', content: new OA\JsonContent(ref: '#/components/schemas/ClusterApplication')),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'The user may not create or place the resource on this environment or server, or may not build on a builder server.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function update(Request $request): JsonResponse
    {
        $workload = $this->clusterWorkload($request, 'application');
        if ($workload === null) {
            return response()->json(['message' => 'Cluster application not found.'], 404);
        }
        $this->authorize('update', $workload);
        $invalid = $this->validateRequest($request, [
            'name' => 'string', 'description' => 'string|nullable',
            'docker_registry_image_name' => 'string|nullable', 'docker_registry_image_tag' => 'string|nullable',
            'registry_credential_uuid' => 'string|nullable', 'start_command' => 'string|nullable',
            'git_branch' => 'string', 'build_pack' => 'string', 'base_directory' => 'string|nullable',
            'dockerfile_location' => 'string|nullable', 'publish_directory' => 'string|nullable', 'dockerfile' => 'string',
            'is_auto_deploy_enabled' => 'boolean', 'is_auto_cancel_queued_deployments_enabled' => 'boolean', 'is_auto_cancel_building_deployments_enabled' => 'boolean',
        ]);
        if ($invalid !== null) {
            return $invalid;
        }

        return $this->runClusterAction(function () use ($request, $workload): JsonResponse {
            $settings = UpdateNodeWorkloadSettings::make();
            if ($request->hasAny(['name', 'description'])) {
                $settings->details($workload, (string) $request->input('name', $workload->name), $request->has('description') ? $request->input('description') : $workload->description);
            }
            $revision = $workload->revisions()->latest('id')->first();
            $command = $request->has('start_command')
                ? (string) $request->input('start_command')
                : collect($revision?->configuration['command'] ?? [])->map(fn (string $argument): string => preg_match('/[\s"]/', $argument) === 1 ? '"'.str_replace('"', '""', $argument).'"' : $argument)->implode(' ');
            $credential = $request->has('registry_credential_uuid') ? (string) $request->input('registry_credential_uuid') : (string) $workload->registryCredential?->uuid;
            if ($workload->buildsFromSource()) {
                if ($request->hasAny(['docker_registry_image_name', 'docker_registry_image_tag', 'registry_credential_uuid', 'start_command', 'git_branch', 'build_pack', 'base_directory', 'dockerfile_location', 'publish_directory', 'dockerfile'])) {
                    $settings->source($workload, [
                        'git_branch' => $request->input('git_branch', $workload->git_branch),
                        'build_pack' => $request->input('build_pack', $workload->source_type?->value),
                        'dockerfile' => $request->input('dockerfile', $workload->dockerfile),
                        'base_directory' => $request->input('base_directory', $workload->base_directory),
                        'dockerfile_location' => $request->input('dockerfile_location', $workload->dockerfile_location),
                        'publish_directory' => $request->input('publish_directory', $workload->publish_directory),
                        'start_command' => $command,
                        'docker_registry_image_name' => $request->input('docker_registry_image_name', $workload->docker_registry_image_name),
                        'docker_registry_image_tag' => $request->input('docker_registry_image_tag', $workload->docker_registry_image_tag),
                        'registry_credential_uuid' => $credential,
                    ], $request->user());
                }
            } elseif ($request->hasAny(['git_branch', 'build_pack', 'base_directory', 'dockerfile_location', 'publish_directory', 'dockerfile'])) {
                return response()->json(['message' => 'This application runs a Docker image. It has no Git or Dockerfile settings.'], 422);
            } elseif ($request->hasAny(['docker_registry_image_name', 'docker_registry_image_tag', 'registry_credential_uuid', 'start_command'])) {
                $current = (new DockerImageParser)->parse($revision?->image ?? '');
                $settings->image(
                    $workload,
                    (string) $request->input('docker_registry_image_name', $current->getFullImageNameWithoutTag()),
                    $request->input('docker_registry_image_tag', $current->getTag()),
                    $command,
                    $credential,
                    $request->user(),
                );
            }
            if ($request->hasAny(['is_auto_deploy_enabled', 'is_auto_cancel_queued_deployments_enabled', 'is_auto_cancel_building_deployments_enabled'])) {
                $settings->deploymentSettings(
                    $workload,
                    $request->boolean('is_auto_deploy_enabled', (bool) $workload->is_auto_deploy_enabled),
                    $request->boolean('is_auto_cancel_queued_deployments_enabled', (bool) $workload->is_auto_cancel_queued_deployments_enabled),
                    $request->boolean('is_auto_cancel_building_deployments_enabled', (bool) $workload->is_auto_cancel_building_deployments_enabled),
                );
            }

            return response()->json($this->workloadResponse($workload->refresh()));
        });
    }

    #[OA\Delete(
        summary: 'Delete',
        description: 'Delete a cluster application. Coolify removes its containers on every server in the background.',
        path: '/cluster-applications/{uuid}',
        operationId: 'delete-cluster-application-by-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Applications'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster application.', required: true, schema: new OA\Schema(type: 'string')),
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
        return $this->deleteWorkload($request, 'application', 'Cluster application');
    }

    #[OA\Post(
        summary: 'Deploy',
        description: 'Deploy the latest configuration on every server of the application. An application that Coolify builds starts a build, which deploys the result.',
        path: '/cluster-applications/{uuid}/deploy',
        operationId: 'deploy-cluster-application-by-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Applications'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster application.', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The deployment or build is queued.', content: new OA\JsonContent(ref: '#/components/schemas/ClusterDeploymentQueued')),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'The user may not create or place the resource on this environment or server, or may not build on a builder server.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function deploy(Request $request): JsonResponse
    {
        return $this->deployWorkload($request, 'application', 'Cluster application');
    }

    #[OA\Post(
        summary: 'Start',
        description: 'Start a cluster application. Like v4, this deploys it.',
        path: '/cluster-applications/{uuid}/start',
        operationId: 'start-cluster-application-by-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Applications'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster application.', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The deployment or build is queued.', content: new OA\JsonContent(ref: '#/components/schemas/ClusterDeploymentQueued')),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'The user may not create or place the resource on this environment or server, or may not build on a builder server.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function start(Request $request): JsonResponse
    {
        return $this->deployWorkload($request, 'application', 'Cluster application');
    }

    #[OA\Post(
        summary: 'Stop',
        description: 'Stop a cluster application on every server.',
        path: '/cluster-applications/{uuid}/stop',
        operationId: 'stop-cluster-application-by-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Applications'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster application.', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The stop is queued.', content: new OA\JsonContent(ref: '#/components/schemas/ClusterLifecycleQueued')),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function stop(Request $request): JsonResponse
    {
        return $this->lifecycle($request, 'application', NodeWorkloadAction::STOP);
    }

    #[OA\Post(
        summary: 'Restart',
        description: 'Restart a cluster application on every server.',
        path: '/cluster-applications/{uuid}/restart',
        operationId: 'restart-cluster-application-by-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Applications'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster application.', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The restart is queued.', content: new OA\JsonContent(ref: '#/components/schemas/ClusterLifecycleQueued')),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function restart(Request $request): JsonResponse
    {
        return $this->lifecycle($request, 'application', NodeWorkloadAction::RESTART);
    }

    #[OA\Get(
        summary: 'List Revisions',
        description: 'List the latest 50 revisions of a cluster application, newest first. Environment variables and secrets are not returned.',
        path: '/cluster-applications/{uuid}/revisions',
        operationId: 'list-cluster-application-revisions',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Cluster Applications'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster application.', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The revisions of the cluster application.',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(
                        type: 'object',
                        properties: [
                            'uuid' => new OA\Property(property: 'uuid', type: 'string'),
                            'image' => new OA\Property(property: 'image', type: 'string'),
                            'commit_sha' => new OA\Property(property: 'commit_sha', type: 'string', nullable: true),
                            'created_at' => new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                            'current' => new OA\Property(property: 'current', type: 'boolean', description: 'The latest revision, which deploys next.'),
                            'last_deployment' => new OA\Property(
                                property: 'last_deployment',
                                type: 'object',
                                nullable: true,
                                properties: [
                                    'uuid' => new OA\Property(property: 'uuid', type: 'string'),
                                    'status' => new OA\Property(property: 'status', type: 'string'),
                                    'server' => new OA\Property(property: 'server', type: 'string', nullable: true),
                                    'finished_at' => new OA\Property(property: 'finished_at', type: 'string', format: 'date-time', nullable: true),
                                ],
                            ),
                            'can_roll_back' => new OA\Property(property: 'can_roll_back', type: 'boolean', description: 'An earlier image that deployed successfully.'),
                        ],
                    ),
                ),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function revisions(Request $request): JsonResponse
    {
        $workload = $this->clusterWorkload($request, 'application');
        if ($workload === null) {
            return response()->json(['message' => 'Cluster application not found.'], 404);
        }
        $this->authorize('view', $workload);

        $revisions = $workload->revisions()->with('build')->latest('id')->limit(self::REVISION_LIMIT)->get();
        $currentImage = $revisions->first()?->image;
        $deployments = NodeOperation::query()
            ->with('node')
            ->where('command_type', 'workload.deploy.v1')
            ->whereIn('node_workload_revision_id', $revisions->pluck('id'))
            ->latest('id')
            ->get()
            ->groupBy('node_workload_revision_id');

        return response()->json($revisions->map(function (NodeWorkloadRevision $revision, int $index) use ($workload, $currentImage, $deployments): array {
            $revisionDeployments = $deployments->get($revision->id, collect());
            $last = $revisionDeployments->first();

            return [
                'uuid' => $revision->uuid,
                'image' => $revision->image,
                'commit_sha' => $revision->build?->commit_sha,
                'created_at' => $revision->created_at,
                'current' => $index === 0,
                'last_deployment' => $last === null ? null : [
                    'uuid' => $last->uuid,
                    'status' => $last->status->value,
                    'server' => $last->node?->name,
                    'finished_at' => $last->completed_at,
                ],
                'can_roll_back' => ! $workload->isDatabase()
                    && $revision->image !== $currentImage
                    && $revisionDeployments->contains(fn (NodeOperation $operation): bool => $operation->status === NodeOperationStatus::SUCCEEDED),
            ];
        })->values());
    }

    #[OA\Post(
        summary: 'Roll Back',
        description: 'Roll a cluster application back to the image of an earlier revision. Environment variables, secrets, and settings keep their current values, and volume data is not rolled back. When the cluster registry no longer has the image, Coolify builds the same commit again and deploys the result.',
        path: '/cluster-applications/{uuid}/rollback',
        operationId: 'rollback-cluster-application',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Cluster Applications'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster application.', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: 'object',
                required: ['revision_uuid'],
                properties: [
                    'revision_uuid' => new OA\Property(property: 'revision_uuid', type: 'string', description: 'UUID of the revision to roll back to, from List Revisions.'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'The rollback is queued.',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        'message' => new OA\Property(property: 'message', type: 'string'),
                        'deployment_uuid' => new OA\Property(property: 'deployment_uuid', type: 'string', nullable: true),
                        'build_uuid' => new OA\Property(property: 'build_uuid', type: 'string', nullable: true, description: 'Set when Coolify builds the image again.'),
                    ],
                ),
            ),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function rollback(Request $request): JsonResponse
    {
        $workload = $this->clusterWorkload($request, 'application');
        if ($workload === null) {
            return response()->json(['message' => 'Cluster application not found.'], 404);
        }
        $this->authorize('deploy', $workload);

        $validator = customApiValidator($request->all(), ['revision_uuid' => 'required|string']);
        $extraFields = array_diff(array_keys($request->all()), ['revision_uuid']);
        if ($validator->fails() || $extraFields !== []) {
            $errors = $validator->errors();
            foreach ($extraFields as $field) {
                $errors->add($field, 'This field is not allowed.');
            }

            return response()->json(['message' => 'Validation failed.', 'errors' => $errors], 422);
        }
        $revision = $workload->revisions()->where('uuid', $request->input('revision_uuid'))->first();
        if ($revision === null) {
            return response()->json(['message' => 'Revision not found.'], 404);
        }

        try {
            $result = RollbackNodeWorkload::run($workload, $revision, auth()->user());
        } catch (RuntimeException|DomainException|InvalidArgumentException $exception) {
            // Every one of these says what blocks the rollback: the revision, the build, or a server.
            return response()->json(['message' => $exception->getMessage()], 400);
        }

        $deploymentUuid = $result['operation']?->uuid;
        $buildUuid = $result['build']?->uuid;
        auditLog('api.deployment.rollback', [
            'resource_type' => 'cluster-application',
            'workload_uuid' => $workload->uuid,
            'workload_name' => $workload->name,
            'revision_uuid' => $revision->uuid,
            'deployment_uuid' => $deploymentUuid,
            'build_uuid' => $buildUuid,
        ]);

        return response()->json([
            'message' => $buildUuid !== null
                ? "The image is no longer in the cluster registry. Application {$workload->name} builds it again and then deploys."
                : "Application {$workload->name} rollback queued.",
            'deployment_uuid' => $deploymentUuid,
            'build_uuid' => $buildUuid,
        ]);
    }

    /** The Git create endpoints: a public repository, a GitHub App, or a deploy key. */
    private function createFromGit(Request $request, string $source): JsonResponse
    {
        $invalid = $this->validateRequest($request, [
            ...$this->clusterTargetRules(),
            'git_repository' => $this->gitRepositoryRules($source),
            'git_branch' => ['required', 'string', 'max:255', new ValidGitBranch],
            'build_pack' => ['required', Rule::in(array_map(fn (NodeWorkloadSourceType $type): string => $type->value, NodeWorkloadSourceType::gitBuildPacks()))],
            'base_directory' => self::PATH_RULES,
            'dockerfile_location' => self::PATH_RULES,
            'publish_directory' => self::PATH_RULES,
            ...($source === 'github-app' ? ['github_app_uuid' => 'required|string'] : []),
            ...($source === 'deploy-key' ? ['private_key_uuid' => 'required|string'] : []),
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
        $source = $this->gitSource($request, $project, $source);
        if ($source instanceof JsonResponse) {
            return $source;
        }
        [$repository, $githubApp, $privateKey] = $source;

        return $this->runClusterAction(function () use ($request, $project, $environment, $cluster, $node, $repository, $githubApp, $privateKey): JsonResponse {
            $workload = CreateClusterGitApplication::run($project, $environment, $cluster, $node, (string) $request->input('build_pack'), $repository, trim((string) $request->input('git_branch')), $request->user(), $githubApp, $privateKey, [
                'name' => $request->input('name'),
                'base_directory' => $request->input('base_directory'),
                'dockerfile_location' => $request->input('dockerfile_location'),
                'publish_directory' => $request->input('publish_directory'),
            ]);

            return $this->created($request, $workload);
        });
    }
}
