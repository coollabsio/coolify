<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Actions\Node\Build\IssueNodeGitCredential;
use App\Actions\Node\DeployNodeWorkload;
use App\Actions\Node\DetermineWorkloadState;
use App\Actions\Node\QueueNodeWorkloadLifecycle;
use App\Enums\NodeWorkloadAction;
use App\Enums\NodeWorkloadState;
use App\Exceptions\NodePlacementDeniedException;
use App\Jobs\DeleteNodeWorkloadJob;
use App\Livewire\Project\New\ClusterGithubPrivateRepositoryDeployKey;
use App\Livewire\Project\New\DockerImage;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\NodeOperation;
use App\Models\NodeStack;
use App\Models\NodeWorkload;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Rules\ValidGitRepositoryUrl;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * The shared parts of the cluster resource API (decision 0029): lookups in the team of the token
 * (the access grants of the token user apply through `VisibleThroughAccessGrants`), the target of
 * a new resource, the response of a workload, and the error responses of the actions.
 */
trait HandlesClusterResourcesApi
{
    /**
     * Cluster applications: not a database, a stack service, or a preview. Databases: a database
     * type. `any`: every workload of the team.
     *
     * @return Builder<NodeWorkload>
     */
    protected function clusterWorkloads(string $kind): Builder
    {
        return NodeWorkload::query()
            ->where('team_id', getTeamIdFromToken())
            ->when($kind === 'application', fn (Builder $query) => $query->whereNull('database_type')->whereNull('node_stack_id')->whereNull('preview_of_id'))
            ->when($kind === 'database', fn (Builder $query) => $query->whereNotNull('database_type'));
    }

    protected function clusterWorkload(Request $request, string $kind): ?NodeWorkload
    {
        return $this->clusterWorkloads($kind)->where('uuid', (string) $request->route('uuid'))->first();
    }

    protected function clusterStack(Request $request): ?NodeStack
    {
        return NodeStack::query()->where('team_id', getTeamIdFromToken())->where('uuid', (string) $request->route('uuid'))->first();
    }

    /**
     * The workload of a route with the `cluster_kind` default: a cluster application, a cluster
     * database, or the service of a cluster stack, named by `service` in the query or the body.
     * The `service` field is removed from the request after the lookup.
     */
    protected function kindWorkload(Request $request): NodeWorkload|JsonResponse
    {
        $kind = (string) $request->route('cluster_kind');
        if ($kind !== 'stack') {
            return $this->clusterWorkload($request, $kind)
                ?? response()->json(['message' => $kind === 'database' ? 'Cluster database not found.' : 'Cluster application not found.'], 404);
        }
        $stack = $this->clusterStack($request);
        if ($stack === null) {
            return response()->json(['message' => 'Cluster stack not found.'], 404);
        }
        $service = (string) $request->input('service', $request->query('service', ''));
        foreach ([$request->query, $request->request] as $bag) {
            $bag->remove('service');
        }
        if ($request->isJson()) {
            $request->json()->remove('service');
        }
        if ($service === '') {
            return response()->json(['message' => 'Validation failed.', 'errors' => ['service' => ['The service field is required.']]], 422);
        }

        return $stack->workloads()->where('stack_service', $service)->first()
            ?? response()->json(['message' => 'Service not found.'], 404);
    }

    /**
     * The project, environment, cluster, and optional server of a new resource. Each UUID must be
     * of the team of the token. `server_uuid` places it on one server of the cluster.
     *
     * @return array{0: Project, 1: Environment, 2: NodeCluster, 3: ?Node}|JsonResponse
     */
    protected function clusterTarget(Request $request): array|JsonResponse
    {
        $teamId = getTeamIdFromToken();
        $project = Project::query()->where('team_id', $teamId)->where('uuid', (string) $request->input('project_uuid'))->first();
        if ($project === null) {
            return response()->json(['message' => 'Project not found.'], 404);
        }
        $environment = $project->environments()
            ->when($request->filled('environment_uuid'),
                fn ($query) => $query->where('uuid', (string) $request->input('environment_uuid')),
                fn ($query) => $query->where('name', (string) $request->input('environment_name')))
            ->first();
        if ($environment === null) {
            return response()->json(['message' => 'Environment not found.'], 404);
        }
        try {
            [$cluster, $node] = $request->filled('server_uuid')
                ? DockerImage::resolveClusterTarget($project, 'node', (string) $request->input('server_uuid'))
                : DockerImage::resolveClusterTarget($project, 'cluster', (string) $request->input('cluster_uuid'));
        } catch (ModelNotFoundException) {
            return response()->json(['message' => $request->filled('server_uuid') ? 'Server not found.' : 'Cluster not found.'], 404);
        }
        if ($request->filled('server_uuid') && $request->filled('cluster_uuid') && $cluster->uuid !== $request->input('cluster_uuid')) {
            return response()->json(['message' => 'The server is not in this cluster.'], 422);
        }

        return [$project, $environment, $cluster, $node];
    }

    /**
     * The repository and the credential of a create request from Git. `github-app`: `git_repository`
     * is `owner/repository` of the GitHub App. `deploy-key`: a private key of the team.
     *
     * @return array{0: string, 1: ?GithubApp, 2: ?PrivateKey}|JsonResponse
     */
    protected function gitSource(Request $request, Project $project, string $source): array|JsonResponse
    {
        $repository = trim((string) $request->input('git_repository'));
        if ($source === 'github-app') {
            $githubApp = IssueNodeGitCredential::githubApps($project->team_id)->where('uuid', (string) $request->input('github_app_uuid'))->first();
            if ($githubApp === null) {
                return response()->json(['message' => 'GitHub App not found.'], 404);
            }

            return [rtrim($githubApp->html_url, '/').'/'.$repository, $githubApp, null];
        }
        if ($source === 'deploy-key') {
            $privateKey = IssueNodeGitCredential::privateKeys($project->team_id)->where('uuid', (string) $request->input('private_key_uuid'))->first();
            if ($privateKey === null) {
                return response()->json(['message' => 'Private key not found.'], 404);
            }

            return [ClusterGithubPrivateRepositoryDeployKey::sshUrl($repository), null, $privateKey];
        }

        return [$repository, null, null];
    }

    /** @return array<int, mixed> The rules of `git_repository` for a source. */
    protected function gitRepositoryRules(string $source): array
    {
        return match ($source) {
            'public' => ['required', 'string', 'max:2048', 'starts_with:https://', new ValidGitRepositoryUrl(allowSSH: false)],
            'github-app' => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9\-_]+\/[a-zA-Z0-9\-_\.]+$/'],
            default => ['required', 'string', 'max:2048', new ValidGitRepositoryUrl],
        };
    }

    /** @return array<string, array<int, mixed>> The rules of the target fields of a create request. */
    protected function clusterTargetRules(): array
    {
        return [
            'project_uuid' => 'required|string',
            'environment_uuid' => 'required_without:environment_name|string|nullable',
            'environment_name' => 'required_without:environment_uuid|string|nullable',
            'cluster_uuid' => 'required_without:server_uuid|string|nullable',
            'server_uuid' => 'required_without:cluster_uuid|string|nullable',
            'name' => 'string|nullable|max:255',
            'description' => 'string|nullable|max:255',
            'instant_deploy' => 'boolean',
        ];
    }

    /**
     * Validates the request. Fields outside the rules are refused, like the v4 API.
     *
     * @param  array<string, mixed>  $rules
     * @param  array<string, string>  $messages
     */
    protected function validateRequest(Request $request, array $rules, array $messages = []): ?JsonResponse
    {
        $validator = customApiValidator($request->all(), $rules, $messages);
        $allowed = collect(array_keys($rules))->map(fn (string $key): string => explode('.', $key)[0])->unique()->all();
        $extraFields = array_diff(array_keys($request->all()), $allowed);
        if ($validator->fails() || $extraFields !== []) {
            $errors = $validator->errors();
            foreach ($extraFields as $field) {
                $errors->add($field, 'This field is not allowed.');
            }

            return response()->json(['message' => 'Validation failed.', 'errors' => $errors], 422);
        }

        return null;
    }

    /**
     * Runs an action and gives its errors as API responses: 422 for a validation error, 404 for a
     * missing record, 403 for a refused placement, 400 with the reason for a refused change. An
     * authorization error stays a 403.
     */
    protected function runClusterAction(callable $action): mixed
    {
        try {
            return $action();
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (ValidationException $exception) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $exception->errors()], 422);
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Not found.'], 404);
        } catch (NodePlacementDeniedException $exception) {
            return response()->json(['message' => $exception->getMessage()], 403);
        } catch (RuntimeException|DomainException|InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 400);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'The request failed. See the Coolify log.'], 500);
        }
    }

    /** Sensitive values show for a team admin with a `root` or `read:sensitive` token (ApiSensitiveData) who may view them. */
    protected function canReadClusterSecrets(NodeWorkload $workload): bool
    {
        return request()->attributes->get('can_read_sensitive', false) === true
            && Gate::allows('viewSensitive', $workload);
    }

    /** `running:healthy`, `exited`, or the worst state of the servers, like the v4 status. */
    protected function workloadStatus(NodeWorkload $workload): string
    {
        $states = $workload->nodes->map(fn (Node $node): NodeWorkloadState => DetermineWorkloadState::run($node, $workload));
        $state = $states->first(fn (NodeWorkloadState $state): bool => $state !== NodeWorkloadState::RUNNING)
            ?? $states->first()
            ?? NodeWorkloadState::UNKNOWN;
        if ($state === NodeWorkloadState::RUNNING) {
            $health = $workload->observedHealth();

            return 'running'.($health === null ? '' : ":{$health}");
        }

        return $state->value;
    }

    /** @return array<string, mixed> */
    protected function workloadResponse(NodeWorkload $workload): array
    {
        $workload->loadMissing(['nodes', 'project', 'environment']);
        $revision = $workload->revisions()->latest('id')->first();
        $configuration = $revision?->configuration ?? [];
        $resources = $configuration['resources'] ?? [];
        $sensitive = $this->canReadClusterSecrets($workload);
        $cluster = $workload->nodes->first()?->cluster;

        return [
            'uuid' => $workload->uuid,
            'name' => $workload->name,
            'description' => $workload->description,
            'type' => $workload->isDatabase() ? 'database' : ($workload->node_stack_id !== null ? 'stack-service' : ($workload->isPreview() ? 'preview' : 'application')),
            'status' => $this->workloadStatus($workload),
            'desired_state' => $workload->desired_state?->value,
            'build_pack' => $workload->source_type?->value,
            'database_type' => $workload->database_type?->value,
            'image' => $revision?->image,
            'git_repository' => $workload->git_repository,
            'git_branch' => $workload->git_branch,
            'base_directory' => $workload->base_directory,
            'dockerfile_location' => $workload->dockerfile_location,
            'publish_directory' => $workload->publish_directory,
            'docker_registry_image_name' => $workload->docker_registry_image_name,
            'docker_registry_image_tag' => $workload->docker_registry_image_tag,
            'registry_credential_uuid' => $workload->registryCredential?->uuid,
            'github_app_uuid' => $workload->githubApp?->uuid,
            'private_key_uuid' => $workload->privateKey?->uuid,
            // Commands and inline Dockerfiles can contain secrets, like the v4 command fields.
            'start_command' => $sensitive ? ($configuration['command'] ?? []) : null,
            'dockerfile' => $sensitive ? $workload->dockerfile : null,
            'domains' => collect($workload->domain_routes ?? [])->map(fn (array $route): string => $route['scheme'].'://'.$route['host'].(filled($route['port'] ?? null) ? ':'.$route['port'] : '').($route['path'] ?? ''))->values()->all(),
            'force_https' => (bool) $workload->force_https,
            'www_redirect' => $workload->www_redirect,
            'permanent_redirect' => (bool) $workload->permanent_redirect,
            // Proxy options of the domains (decision 0031). The password is sensitive, the hash is never returned.
            'is_http_basic_auth_enabled' => (bool) $workload->is_http_basic_auth_enabled,
            'http_basic_auth_username' => $workload->http_basic_auth_username,
            'http_basic_auth_password' => $sensitive ? $workload->http_basic_auth_password : null,
            'ingress_allowed_ips' => array_values($workload->ingress_allowed_ips ?? []),
            'ingress_request_headers' => array_values($workload->ingress_request_headers ?? []),
            'ingress_response_headers' => array_values($workload->ingress_response_headers ?? []),
            'is_gzip_enabled' => $workload->is_gzip_enabled !== false,
            'is_stripprefix_enabled' => $workload->is_stripprefix_enabled !== false,
            'custom_docker_run_options' => $workload->custom_docker_run_options,
            'custom_labels' => $workload->custom_labels,
            'is_gpu_enabled' => (bool) $workload->is_gpu_enabled,
            'gpu_count' => $workload->gpu_count,
            'gpu_device_ids' => $workload->gpu_device_ids,
            'pre_deployment_command' => $workload->pre_deployment_command,
            'post_deployment_command' => $workload->post_deployment_command,
            'deployment_command_timeout' => (int) ($workload->deployment_command_timeout ?: 300),
            'internal_hostname' => $workload->internal_dns_name ? $workload->internal_dns_name.'.default.coolify.internal' : null,
            'cpu_limit' => $resources['cpu_limit'] ?? null,
            'cpu_reservation' => $resources['cpu_reservation'] ?? null,
            'memory_limit_mb' => isset($resources['memory_limit_bytes']) ? intdiv((int) $resources['memory_limit_bytes'], 1_048_576) : null,
            'memory_reservation_mb' => isset($resources['memory_reservation_bytes']) ? intdiv((int) $resources['memory_reservation_bytes'], 1_048_576) : null,
            'is_auto_deploy_enabled' => (bool) $workload->is_auto_deploy_enabled,
            'is_auto_cancel_queued_deployments_enabled' => (bool) $workload->is_auto_cancel_queued_deployments_enabled,
            'is_auto_cancel_building_deployments_enabled' => (bool) $workload->is_auto_cancel_building_deployments_enabled,
            'watch_paths' => $workload->watch_paths,
            'is_git_submodules_enabled' => $workload->is_git_submodules_enabled !== false,
            'is_git_lfs_enabled' => $workload->is_git_lfs_enabled !== false,
            'is_git_shallow_clone_enabled' => $workload->is_git_shallow_clone_enabled !== false,
            'preview_deployments_enabled' => (bool) $workload->preview_deployments_enabled,
            'stack_uuid' => $workload->node_stack_id === null ? null : $workload->stack?->uuid,
            'stack_service' => $workload->stack_service,
            'project_uuid' => $workload->project?->uuid,
            'environment_uuid' => $workload->environment?->uuid,
            'cluster_uuid' => $cluster?->uuid,
            'servers' => $workload->nodes->map(fn (Node $node): array => ['uuid' => $node->uuid, 'name' => $node->name])->values()->all(),
            'created_at' => $workload->created_at,
            'updated_at' => $workload->updated_at,
        ];
    }

    /** Deletes a workload in the background, like the Danger Zone. Its containers are removed on every server. */
    protected function deleteWorkload(Request $request, string $kind, string $label): JsonResponse
    {
        $workload = $this->clusterWorkload($request, $kind);
        if ($workload === null) {
            return response()->json(['message' => "{$label} not found."], 404);
        }
        $this->authorize('delete', $workload);
        DeleteNodeWorkloadJob::dispatch($workload->id, $request->user()->id, deleteVolumes: $request->boolean('delete_volumes', true));
        auditLog('api.cluster.workload_deleted', [
            'team_id' => $workload->team_id,
            'workload_uuid' => $workload->uuid,
            'workload_name' => $workload->name,
        ]);

        return response()->json(['message' => "{$label} deletion request queued."]);
    }

    protected function deployWorkload(Request $request, string $kind, string $label): JsonResponse
    {
        $workload = $this->clusterWorkload($request, $kind);
        if ($workload === null) {
            return response()->json(['message' => "{$label} not found."], 404);
        }
        $this->authorize('deploy', $workload);

        return $this->runClusterAction(function () use ($request, $workload, $label): JsonResponse {
            ['operation' => $operation, 'build' => $build] = DeployNodeWorkload::run($workload, $request->user());
            if ($operation === null && $build === null) {
                return response()->json(['message' => "This {$label} already has an active operation."], 400);
            }

            return response()->json([
                'message' => $build !== null ? "Build of {$workload->name} queued. It deploys the result." : "Deployment of {$workload->name} queued.",
                'deployment_uuid' => $operation?->uuid,
                'build_uuid' => $build?->uuid,
            ]);
        });
    }

    protected function lifecycle(Request $request, string $kind, NodeWorkloadAction $action): JsonResponse
    {
        $workload = $this->clusterWorkload($request, $kind);
        if ($workload === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }
        $this->authorize('deploy', $workload);

        return $this->runClusterAction(fn (): JsonResponse => $this->lifecycleResponse($workload->name, $action, QueueNodeWorkloadLifecycle::run($workload, $action, $request->user())));
    }

    /** The response of a new workload. `instant_deploy` deploys it, like v4. */
    protected function created(Request $request, NodeWorkload $workload): JsonResponse
    {
        if (filled($request->input('description'))) {
            $workload->update(['description' => trim((string) $request->input('description'))]);
        }
        $deployment = $request->boolean('instant_deploy') && $request->user()->can('deploy', $workload)
            ? DeployNodeWorkload::run($workload, $request->user())
            : ['operation' => null, 'build' => null];
        auditLog('api.cluster.workload_created', [
            'team_id' => $workload->team_id,
            'workload_uuid' => $workload->uuid,
            'workload_name' => $workload->name,
        ]);

        return response()->json([
            'uuid' => $workload->uuid,
            'deployment_uuid' => $deployment['operation']?->uuid,
            'build_uuid' => $deployment['build']?->uuid,
        ], 201);
    }

    /**
     * The response of a queued lifecycle action on every server.
     *
     * @param  array{operations: list<NodeOperation>, failures: list<string>}  $result
     */
    protected function lifecycleResponse(string $label, NodeWorkloadAction $action, array $result): JsonResponse
    {
        $queued = $result['operations'] !== [];

        return response()->json([
            'message' => $queued
                ? str($action->value)->title()." of {$label} queued.".($result['failures'] === [] ? '' : ' Some servers refused it.')
                : "No server accepted the {$action->value}.",
            'deployment_uuids' => array_map(fn ($operation): string => $operation->uuid, $result['operations']),
            'failures' => $result['failures'],
        ], $queued ? 200 : 400);
    }
}
