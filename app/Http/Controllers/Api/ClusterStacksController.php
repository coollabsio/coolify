<?php

namespace App\Http\Controllers\Api;

use App\Actions\Node\Build\CreateClusterGitApplication;
use App\Actions\Node\CreateClusterStack;
use App\Actions\Node\DeployNodeStack;
use App\Actions\Node\QueueNodeWorkloadLifecycle;
use App\Actions\Node\SyncNodeStackCompose;
use App\Enums\AccessLevel;
use App\Enums\NodeWorkloadAction;
use App\Enums\NodeWorkloadSourceType;
use App\Http\Controllers\Api\Concerns\HandlesClusterResourcesApi;
use App\Http\Controllers\Controller;
use App\Jobs\DeleteNodeStackJob;
use App\Models\NodeStack;
use App\Models\NodeWorkload;
use App\Policies\NodeWorkloadPolicy;
use App\Rules\ValidGitBranch;
use App\Services\NodeStack\NodeStackCompose;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/** Stacks on a cluster: a one-click service or a Compose file from Git (decisions 0023 and 0029). */
class ClusterStacksController extends Controller
{
    use HandlesClusterResourcesApi;

    private const PATH_RULES = ['nullable', 'string', 'max:512', 'regex:/^\/?[A-Za-z0-9._\/-]*$/', 'not_regex:/(^|\/)\.\.(\/|$)/'];

    #[OA\Get(
        summary: 'List',
        description: 'List the cluster stacks of the team.',
        path: '/cluster-stacks',
        operationId: 'list-cluster-stacks',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Stacks'],
        responses: [
            new OA\Response(response: 200, description: 'The cluster stacks.', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/ClusterStack'))),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $stacks = NodeStack::query()->where('team_id', getTeamIdFromToken())->with(['project', 'environment', 'cluster'])->orderBy('id')->get();

        return response()->json($stacks->map(fn (NodeStack $stack): array => $this->stackResponse($stack, withServices: false))->values());
    }

    #[OA\Get(
        summary: 'Get',
        description: 'Get a cluster stack with its services.',
        path: '/cluster-stacks/{uuid}',
        operationId: 'get-cluster-stack-by-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Stacks'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster stack.', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The cluster stack.', content: new OA\JsonContent(ref: '#/components/schemas/ClusterStack')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function show(Request $request): JsonResponse
    {
        $stack = $this->clusterStack($request);
        if ($stack === null) {
            return response()->json(['message' => 'Cluster stack not found.'], 404);
        }
        $this->authorize('view', $stack);

        return response()->json($this->stackResponse($stack));
    }

    #[OA\Get(
        summary: 'List Services',
        description: 'List the services of a cluster stack. Each service is a cluster application of the stack.',
        path: '/cluster-stacks/{uuid}/services',
        operationId: 'list-cluster-stack-services',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Stacks'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster stack.', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The services.', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/ClusterApplication'))),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function services(Request $request): JsonResponse
    {
        $stack = $this->clusterStack($request);
        if ($stack === null) {
            return response()->json(['message' => 'Cluster stack not found.'], 404);
        }
        $this->authorize('view', $stack);

        return response()->json($this->stackServices($stack)->map(fn (NodeWorkload $workload): array => $this->workloadResponse($workload))->values());
    }

    #[OA\Post(
        summary: 'Create (Template)',
        description: 'Create a cluster stack from a one-click service template, like a one-click service on a cluster in the UI. Without `server_uuid`, `spread` spreads the services across the servers of the cluster.',
        path: '/cluster-stacks/template',
        operationId: 'create-cluster-stack-template',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Stacks'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            type: 'object',
            required: ['project_uuid', 'type'],
            properties: [
                'type' => new OA\Property(property: 'type', type: 'string', description: 'The one-click service template, for example `uptime-kuma`.'),
                'project_uuid' => new OA\Property(property: 'project_uuid', type: 'string'),
                'environment_uuid' => new OA\Property(property: 'environment_uuid', type: 'string'),
                'environment_name' => new OA\Property(property: 'environment_name', type: 'string'),
                'cluster_uuid' => new OA\Property(property: 'cluster_uuid', type: 'string'),
                'server_uuid' => new OA\Property(property: 'server_uuid', type: 'string'),
                'spread' => new OA\Property(property: 'spread', type: 'boolean'),
                'name' => new OA\Property(property: 'name', type: 'string'),
                'instant_deploy' => new OA\Property(property: 'instant_deploy', type: 'boolean'),
            ],
        )),
        responses: [
            new OA\Response(response: 201, description: 'The cluster stack is created.', content: new OA\JsonContent(ref: '#/components/schemas/ClusterResourceCreated')),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'The user may not create or place the resource on this environment or server, or may not build on a builder server.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function createTemplate(Request $request): JsonResponse
    {
        $invalid = $this->validateRequest($request, [
            ...$this->stackTargetRules(),
            'type' => 'required|string|max:255',
        ]);
        if ($invalid !== null) {
            return $invalid;
        }
        $templates = get_service_templates();
        $template = resolve_service_template_key((string) $request->input('type'), $templates);
        $compose = $template === null ? null : NodeStackCompose::templateCompose((string) $template, $templates);
        if ($compose === null) {
            return response()->json(['message' => 'Service template not found.'], 404);
        }
        $target = $this->clusterTarget($request);
        if ($target instanceof JsonResponse) {
            return $target;
        }
        [$project, $environment, $cluster, $node] = $target;
        $this->authorize('create', [NodeStack::class, $environment]);

        return $this->runClusterAction(fn (): JsonResponse => $this->stackCreated($request, CreateClusterStack::run(
            $project,
            $environment,
            $cluster,
            $compose,
            $request->user(),
            $node,
            $request->input('name'),
            template: $template,
            spread: $node === null && $request->boolean('spread'),
        )));
    }

    #[OA\Post(
        summary: 'Create (Git)',
        description: 'Create a cluster stack from a Compose file in Git. Without a credential, `git_repository` is a public HTTPS URL. With `github_app_uuid`, it is `owner/repository`. With `private_key_uuid`, it is an SSH URL. A service with `build` builds from the repository.',
        path: '/cluster-stacks/git',
        operationId: 'create-cluster-stack-git',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Stacks'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            type: 'object',
            required: ['project_uuid', 'git_repository', 'git_branch'],
            properties: [
                'project_uuid' => new OA\Property(property: 'project_uuid', type: 'string'),
                'environment_uuid' => new OA\Property(property: 'environment_uuid', type: 'string'),
                'environment_name' => new OA\Property(property: 'environment_name', type: 'string'),
                'cluster_uuid' => new OA\Property(property: 'cluster_uuid', type: 'string'),
                'server_uuid' => new OA\Property(property: 'server_uuid', type: 'string'),
                'spread' => new OA\Property(property: 'spread', type: 'boolean'),
                'git_repository' => new OA\Property(property: 'git_repository', type: 'string'),
                'git_branch' => new OA\Property(property: 'git_branch', type: 'string'),
                'github_app_uuid' => new OA\Property(property: 'github_app_uuid', type: 'string'),
                'private_key_uuid' => new OA\Property(property: 'private_key_uuid', type: 'string'),
                'base_directory' => new OA\Property(property: 'base_directory', type: 'string'),
                'docker_compose_location' => new OA\Property(property: 'docker_compose_location', type: 'string', description: 'The default is `/docker-compose.yaml`.'),
                'name' => new OA\Property(property: 'name', type: 'string'),
                'instant_deploy' => new OA\Property(property: 'instant_deploy', type: 'boolean'),
            ],
        )),
        responses: [
            new OA\Response(response: 201, description: 'The cluster stack is created.', content: new OA\JsonContent(ref: '#/components/schemas/ClusterResourceCreated')),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'The user may not create or place the resource on this environment or server, or may not build on a builder server.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function createGit(Request $request): JsonResponse
    {
        $source = match (true) {
            $request->filled('github_app_uuid') => 'github-app',
            $request->filled('private_key_uuid') => 'deploy-key',
            default => 'public',
        };
        $invalid = $this->validateRequest($request, [
            ...$this->stackTargetRules(),
            'git_repository' => $this->gitRepositoryRules($source),
            'git_branch' => ['required', 'string', 'max:255', new ValidGitBranch],
            'github_app_uuid' => 'nullable|string|prohibits:private_key_uuid',
            'private_key_uuid' => 'nullable|string',
            'base_directory' => self::PATH_RULES,
            'docker_compose_location' => self::PATH_RULES,
        ]);
        if ($invalid !== null) {
            return $invalid;
        }
        $target = $this->clusterTarget($request);
        if ($target instanceof JsonResponse) {
            return $target;
        }
        [$project, $environment, $cluster, $node] = $target;
        $this->authorize('create', [NodeStack::class, $environment]);
        $gitSource = $this->gitSource($request, $project, $source);
        if ($gitSource instanceof JsonResponse) {
            return $gitSource;
        }
        [$repository, $githubApp, $privateKey] = $gitSource;

        return $this->runClusterAction(fn (): JsonResponse => $this->stackCreated($request, CreateClusterGitApplication::run(
            $project,
            $environment,
            $cluster,
            $node,
            NodeWorkloadSourceType::COMPOSE_BUILD_PACK,
            $repository,
            trim((string) $request->input('git_branch')),
            $request->user(),
            $githubApp,
            $privateKey,
            [
                'name' => $request->input('name'),
                'base_directory' => $request->input('base_directory'),
                'compose_location' => $request->input('docker_compose_location'),
                'spread' => $request->boolean('spread'),
            ],
        )));
    }

    #[OA\Patch(
        summary: 'Update',
        description: 'Change the name of a cluster stack, or give a stack without Git a new Compose file. The services change as for a Git sync: added services are created, changed services get the new settings, and removed services are deleted. Deploy the stack to apply the changes.',
        path: '/cluster-stacks/{uuid}',
        operationId: 'update-cluster-stack-by-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Stacks'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster stack.', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            type: 'object',
            properties: [
                'name' => new OA\Property(property: 'name', type: 'string'),
                'docker_compose_raw' => new OA\Property(property: 'docker_compose_raw', type: 'string', description: 'The Compose file, as plain text or base64.'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'The cluster stack is updated.', content: new OA\JsonContent(type: 'object', properties: [
                new OA\Property(property: 'uuid', type: 'string'),
                new OA\Property(property: 'compose_changes', type: 'object', nullable: true, description: 'The added, changed, and removed services, or null when the Compose file did not change.'),
            ])),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'The user may not create or place the resource on this environment or server, or may not build on a builder server.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function update(Request $request): JsonResponse
    {
        $stack = $this->clusterStack($request);
        if ($stack === null) {
            return response()->json(['message' => 'Cluster stack not found.'], 404);
        }
        $this->authorize('update', $stack);
        $compose = $request->input('docker_compose_raw');
        if (is_string($compose)) {
            $decoded = base64_decode($compose, true);
            if ($decoded !== false && mb_check_encoding($decoded, 'UTF-8') && base64_encode($decoded) === preg_replace('/\s/', '', $compose)) {
                $request->merge(['docker_compose_raw' => $decoded]);
            }
        }
        $invalid = $this->validateRequest($request, [
            'name' => 'sometimes|string|min:1|max:255',
            'docker_compose_raw' => 'sometimes|string|min:1|max:262144',
        ]);
        if ($invalid !== null) {
            return $invalid;
        }

        return $this->runClusterAction(function () use ($request, $stack): JsonResponse {
            $changes = $request->has('docker_compose_raw')
                ? SyncNodeStackCompose::make()->edit($stack, (string) $request->input('docker_compose_raw'), $request->user())
                : null;
            if ($request->has('name')) {
                $stack->update(['name' => trim((string) $request->input('name'))]);
            }
            auditLog('api.cluster.stack_updated', ['team_id' => $stack->team_id, 'stack_uuid' => $stack->uuid, 'stack_name' => $stack->name]);

            return response()->json(['uuid' => $stack->uuid, 'compose_changes' => $changes]);
        });
    }

    #[OA\Delete(
        summary: 'Delete',
        description: 'Delete a cluster stack and all its services in the background.',
        path: '/cluster-stacks/{uuid}',
        operationId: 'delete-cluster-stack-by-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Stacks'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster stack.', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'delete_volumes', in: 'query', description: 'Delete the volumes. The default is true.', required: false, schema: new OA\Schema(type: 'boolean', default: true)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The deletion is queued.', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string')])),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function destroy(Request $request): JsonResponse
    {
        $stack = $this->clusterStack($request);
        if ($stack === null) {
            return response()->json(['message' => 'Cluster stack not found.'], 404);
        }
        $this->authorize('delete', $stack);
        DeleteNodeStackJob::dispatch($stack->id, $request->user()->id, $request->boolean('delete_volumes', true));
        auditLog('api.cluster.stack_deleted', ['team_id' => $stack->team_id, 'stack_uuid' => $stack->uuid, 'stack_name' => $stack->name]);

        return response()->json(['message' => 'Cluster stack deletion request queued.']);
    }

    #[OA\Post(
        summary: 'Deploy',
        description: 'Deploy a cluster stack: each service deploys after the services it depends on. A stack from Git reads its Compose file first.',
        path: '/cluster-stacks/{uuid}/deploy',
        operationId: 'deploy-cluster-stack-by-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Stacks'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster stack.', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The deployment is queued.', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string')])),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'The user may not create or place the resource on this environment or server, or may not build on a builder server.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function deploy(Request $request): JsonResponse
    {
        $stack = $this->clusterStack($request);
        if ($stack === null) {
            return response()->json(['message' => 'Cluster stack not found.'], 404);
        }
        $this->authorize('deploy', $stack);

        return $this->runClusterAction(function () use ($request, $stack): JsonResponse {
            DeployNodeStack::run($stack, $request->user());

            return response()->json(['message' => "Deployment of {$stack->name} queued."]);
        });
    }

    #[OA\Post(
        summary: 'Start',
        description: 'Start a cluster stack. This is the same as a deploy, like the start of a v4 service.',
        path: '/cluster-stacks/{uuid}/start',
        operationId: 'start-cluster-stack-by-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Stacks'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster stack.', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The deployment is queued.', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string')])),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'The user may not create or place the resource on this environment or server, or may not build on a builder server.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function start(Request $request): JsonResponse
    {
        return $this->deploy($request);
    }

    #[OA\Post(
        summary: 'Stop',
        description: 'Stop every service of a cluster stack.',
        path: '/cluster-stacks/{uuid}/stop',
        operationId: 'stop-cluster-stack-by-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Stacks'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster stack.', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The stop is queued.', content: new OA\JsonContent(ref: '#/components/schemas/ClusterLifecycleQueued')),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function stop(Request $request): JsonResponse
    {
        return $this->stackLifecycle($request, NodeWorkloadAction::STOP);
    }

    #[OA\Post(
        summary: 'Restart',
        description: 'Restart every service of a cluster stack.',
        path: '/cluster-stacks/{uuid}/restart',
        operationId: 'restart-cluster-stack-by-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Stacks'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster stack.', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The restart is queued.', content: new OA\JsonContent(ref: '#/components/schemas/ClusterLifecycleQueued')),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function restart(Request $request): JsonResponse
    {
        return $this->stackLifecycle($request, NodeWorkloadAction::RESTART);
    }

    /** @return array<string, mixed> The target rules of a create request. A stack has no description. */
    private function stackTargetRules(): array
    {
        return [
            ...collect($this->clusterTargetRules())->except('description')->all(),
            'spread' => ['boolean', function (string $attribute, mixed $value, \Closure $fail): void {
                if (filter_var($value, FILTER_VALIDATE_BOOLEAN) && filled(request()->input('server_uuid'))) {
                    $fail('Select the cluster, not one server, to spread the stack across the cluster.');
                }
            }],
        ];
    }

    private function stackLifecycle(Request $request, NodeWorkloadAction $action): JsonResponse
    {
        $stack = $this->clusterStack($request);
        if ($stack === null) {
            return response()->json(['message' => 'Cluster stack not found.'], 404);
        }
        $this->authorize('deploy', $stack);

        return $this->runClusterAction(function () use ($request, $stack, $action): JsonResponse {
            $result = ['operations' => [], 'failures' => []];
            foreach ($this->stackServices($stack) as $workload) {
                $serviceResult = QueueNodeWorkloadLifecycle::run($workload, $action, $request->user());
                $result['operations'] = [...$result['operations'], ...$serviceResult['operations']];
                $result['failures'] = [...$result['failures'], ...array_map(fn (string $failure): string => "{$workload->stack_service}: {$failure}", $serviceResult['failures'])];
            }

            return $this->lifecycleResponse($stack->name, $action, $result);
        });
    }

    /** @return Collection<int, NodeWorkload> */
    private function stackServices(NodeStack $stack): Collection
    {
        return NodeWorkload::query()->where('node_stack_id', $stack->id)->with('nodes')->orderBy('id')->get();
    }

    private function stackCreated(Request $request, NodeStack $stack): JsonResponse
    {
        $deploy = $request->boolean('instant_deploy') && $request->user()->can('deploy', $stack);
        if ($deploy) {
            DeployNodeStack::run($stack, $request->user());
        }
        auditLog('api.cluster.stack_created', ['team_id' => $stack->team_id, 'stack_uuid' => $stack->uuid, 'stack_name' => $stack->name]);

        return response()->json(['uuid' => $stack->uuid, 'deployment_uuid' => null, 'build_uuid' => null, 'deploying' => $deploy], 201);
    }

    /** @return array<string, mixed> */
    private function stackResponse(NodeStack $stack, bool $withServices = true): array
    {
        $stack->loadMissing(['project', 'environment', 'cluster', 'githubApp', 'privateKey']);
        // The Compose file and the generated values can contain secrets, like a v4 service.
        $sensitive = request()->attributes->get('can_read_sensitive', false) === true
            && NodeWorkloadPolicy::allowsOn(request()->user(), AccessLevel::ReadSensitive, $stack);

        return [
            'uuid' => $stack->uuid,
            'name' => $stack->name,
            'template' => $stack->template,
            'deploy_status' => $stack->deploy_status,
            'deploy_error' => $stack->deploy_error,
            'git_repository' => $stack->git_repository,
            'git_branch' => $stack->git_branch,
            'base_directory' => $stack->base_directory,
            'docker_compose_location' => $stack->compose_location,
            'commit_sha' => $stack->commit_sha,
            'github_app_uuid' => $stack->githubApp?->uuid,
            'private_key_uuid' => $stack->privateKey?->uuid,
            'docker_compose_raw' => $sensitive ? $stack->compose_raw : null,
            'compose_changes' => $stack->compose_changes,
            'search_domain' => $stack->searchDomain(),
            'project_uuid' => $stack->project?->uuid,
            'environment_uuid' => $stack->environment?->uuid,
            'cluster_uuid' => $stack->cluster?->uuid,
            ...($withServices ? ['services' => $this->stackServices($stack)->map(fn (NodeWorkload $workload): array => [
                'uuid' => $workload->uuid,
                'service' => $workload->stack_service,
                'name' => $workload->name,
                'status' => $this->workloadStatus($workload),
                'servers' => $workload->nodes->map(fn ($node): array => ['uuid' => $node->uuid, 'name' => $node->name])->values()->all(),
            ])->values()->all()] : []),
            'created_at' => $stack->created_at,
            'updated_at' => $stack->updated_at,
        ];
    }
}
