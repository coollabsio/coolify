<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesClusterResourcesApi;
use App\Http\Controllers\Controller;
use App\Models\EnvironmentVariable;
use App\Models\NodeWorkload;
use App\Support\ValidationPatterns;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use OpenApi\Attributes as OA;

/**
 * The environment variables of cluster applications, databases, and the services of stacks
 * (decision 0029), with the rules of the v4 environment variable API. The variables of a stack
 * belong to its services, so a stack call names the `service`. The policy of the workload
 * authorizes each call (`manageEnvironment` for a change).
 */
class ClusterEnvironmentVariablesController extends Controller
{
    use HandlesClusterResourcesApi;

    private const FIELDS = ['key', 'value', 'is_literal', 'is_multiline', 'is_shown_once', 'is_buildtime', 'is_runtime', 'comment'];

    #[OA\Get(
        summary: 'List Envs',
        description: 'List the environment variables of a cluster application.',
        path: '/cluster-applications/{uuid}/envs',
        operationId: 'list-envs-by-application-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Applications'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster application.', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The environment variables. A value shows only for a token that may read sensitive data.', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/EnvironmentVariable'))),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    #[OA\Get(
        summary: 'List Envs',
        description: 'List the environment variables of a cluster database.',
        path: '/cluster-databases/{uuid}/envs',
        operationId: 'list-envs-by-database-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Databases'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster database.', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The environment variables. A value shows only for a token that may read sensitive data.', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/EnvironmentVariable'))),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    #[OA\Get(
        summary: 'List Envs',
        description: 'List the environment variables of a cluster stack. Without `service`, the variables of every service.',
        path: '/cluster-stacks/{uuid}/envs',
        operationId: 'list-envs-by-stack-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Stacks'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster stack.', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'service', in: 'query', description: 'The service of the stack.', required: false, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The environment variables. A value shows only for a token that may read sensitive data.', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/EnvironmentVariable'))),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $workloads = $this->envWorkloads($request, $request->query('service'));
        if ($workloads instanceof JsonResponse) {
            return $workloads;
        }
        $workloads->each(fn (NodeWorkload $workload) => $this->authorize('view', $workload));

        return response()->json($workloads->flatMap(fn (NodeWorkload $workload) => $workload->environment_variables()->get()
            ->map(fn (EnvironmentVariable $env): array => $this->envResponse($workload, $env)))->values());
    }

    #[OA\Post(
        summary: 'Create Env',
        description: 'Create an environment variable of a cluster application.',
        path: '/cluster-applications/{uuid}/envs',
        operationId: 'create-env-by-application-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Applications'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster application.', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ClusterEnvironmentVariableRequest')),
        responses: [
            new OA\Response(response: 201, description: 'The environment variable.', content: new OA\JsonContent(ref: '#/components/schemas/EnvironmentVariable')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 409, description: 'The environment variable already exists.'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    #[OA\Post(
        summary: 'Create Env',
        description: 'Create an environment variable of a cluster database.',
        path: '/cluster-databases/{uuid}/envs',
        operationId: 'create-env-by-database-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Databases'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster database.', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ClusterEnvironmentVariableRequest')),
        responses: [
            new OA\Response(response: 201, description: 'The environment variable.', content: new OA\JsonContent(ref: '#/components/schemas/EnvironmentVariable')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 409, description: 'The environment variable already exists.'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    #[OA\Post(
        summary: 'Create Env',
        description: 'Create an environment variable of a cluster stack. Name the service in `service`.',
        path: '/cluster-stacks/{uuid}/envs',
        operationId: 'create-env-by-stack-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Stacks'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster stack.', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ClusterStackEnvironmentVariableRequest')),
        responses: [
            new OA\Response(response: 201, description: 'The environment variable.', content: new OA\JsonContent(ref: '#/components/schemas/EnvironmentVariable')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 409, description: 'The environment variable already exists.'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $workload = $this->envWorkload($request);
        if ($workload instanceof JsonResponse) {
            return $workload;
        }
        $this->authorize('manageEnvironment', $workload);
        $item = $this->validatedItem($request->except('service'));
        if ($item instanceof JsonResponse) {
            return $item;
        }
        if ($workload->environment_variables()->where('key', $item['key'])->exists()) {
            return response()->json(['message' => 'Environment variable already exists. Use PATCH request to update it.'], 409);
        }
        $env = $workload->environment_variables()->create([
            'is_literal' => false,
            'is_multiline' => false,
            'is_shown_once' => false,
            'is_preview' => false,
            ...$item,
        ]);
        $this->audit('api.cluster.env_created', $workload, $env);

        return response()->json($this->envResponse($workload, $env), 201);
    }

    #[OA\Patch(
        summary: 'Update Env',
        description: 'Update the environment variable of a cluster application with the key `key`.',
        path: '/cluster-applications/{uuid}/envs',
        operationId: 'update-env-by-application-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Applications'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster application.', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ClusterEnvironmentVariableRequest')),
        responses: [
            new OA\Response(response: 201, description: 'The environment variable.', content: new OA\JsonContent(ref: '#/components/schemas/EnvironmentVariable')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    #[OA\Patch(
        summary: 'Update Env',
        description: 'Update the environment variable of a cluster database with the key `key`.',
        path: '/cluster-databases/{uuid}/envs',
        operationId: 'update-env-by-database-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Databases'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster database.', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ClusterEnvironmentVariableRequest')),
        responses: [
            new OA\Response(response: 201, description: 'The environment variable.', content: new OA\JsonContent(ref: '#/components/schemas/EnvironmentVariable')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    #[OA\Patch(
        summary: 'Update Env',
        description: 'Update the environment variable of a cluster stack with the key `key`. Name the service in `service`.',
        path: '/cluster-stacks/{uuid}/envs',
        operationId: 'update-env-by-stack-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Stacks'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster stack.', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ClusterStackEnvironmentVariableRequest')),
        responses: [
            new OA\Response(response: 201, description: 'The environment variable.', content: new OA\JsonContent(ref: '#/components/schemas/EnvironmentVariable')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function update(Request $request): JsonResponse
    {
        $workload = $this->envWorkload($request);
        if ($workload instanceof JsonResponse) {
            return $workload;
        }
        $this->authorize('manageEnvironment', $workload);
        $item = $this->validatedItem($request->except('service'));
        if ($item instanceof JsonResponse) {
            return $item;
        }
        $env = $workload->environment_variables()->where('key', $item['key'])->where('is_preview', false)->first();
        if ($env === null) {
            return response()->json(['message' => 'Environment variable not found.'], 404);
        }
        $env->fill(Arr::except($item, ['key']));
        $env->withoutAuditLogging(fn () => $env->save());
        if (auditSavedFields($env) !== []) {
            $this->audit('api.cluster.env_updated', $workload, $env);
        }

        return response()->json($this->envResponse($workload, $env), 201);
    }

    #[OA\Patch(
        summary: 'Update Envs (Bulk)',
        description: 'Create or update many environment variables of a cluster application.',
        path: '/cluster-applications/{uuid}/envs/bulk',
        operationId: 'update-envs-by-application-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Applications'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster application.', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ClusterEnvironmentVariableRequest'))])),
        responses: [
            new OA\Response(response: 201, description: 'The environment variables.', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/EnvironmentVariable'))),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    #[OA\Patch(
        summary: 'Update Envs (Bulk)',
        description: 'Create or update many environment variables of a cluster database.',
        path: '/cluster-databases/{uuid}/envs/bulk',
        operationId: 'update-envs-by-database-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Databases'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster database.', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ClusterEnvironmentVariableRequest'))])),
        responses: [
            new OA\Response(response: 201, description: 'The environment variables.', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/EnvironmentVariable'))),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    #[OA\Patch(
        summary: 'Update Envs (Bulk)',
        description: 'Create or update many environment variables of a cluster stack. Name the service in `service`.',
        path: '/cluster-stacks/{uuid}/envs/bulk',
        operationId: 'update-envs-by-stack-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Stacks'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster stack.', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['data', 'service'], properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ClusterEnvironmentVariableRequest')), new OA\Property(property: 'service', type: 'string')])),
        responses: [
            new OA\Response(response: 201, description: 'The environment variables.', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/EnvironmentVariable'))),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function bulk(Request $request): JsonResponse
    {
        $workload = $this->envWorkload($request);
        if ($workload instanceof JsonResponse) {
            return $workload;
        }
        $this->authorize('manageEnvironment', $workload);
        $data = $request->input('data');
        if (! is_array($data) || $data === []) {
            return response()->json(['message' => 'Bulk data is required.'], 400);
        }
        $items = [];
        foreach ($data as $item) {
            $item = $this->validatedItem(is_array($item) ? $item : []);
            if ($item instanceof JsonResponse) {
                return $item;
            }
            $items[] = $item;
        }
        $envs = collect($items)->map(fn (array $item): array => $this->envResponse(
            $workload,
            $workload->environment_variables()->updateOrCreate(['key' => $item['key'], 'is_preview' => false], Arr::except($item, ['key'])),
        ));
        auditLog('api.cluster.env_bulk_upserted', ['team_id' => $workload->team_id, 'workload_uuid' => $workload->uuid, 'env_count' => $envs->count()]);

        return response()->json($envs->values(), 201);
    }

    #[OA\Delete(
        summary: 'Delete Env',
        description: 'Delete an environment variable of a cluster application.',
        path: '/cluster-applications/{uuid}/envs/{env_uuid}',
        operationId: 'delete-env-by-application-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Applications'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster application.', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'env_uuid', in: 'path', description: 'UUID of the environment variable.', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The environment variable is deleted.', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string')])),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    #[OA\Delete(
        summary: 'Delete Env',
        description: 'Delete an environment variable of a cluster database.',
        path: '/cluster-databases/{uuid}/envs/{env_uuid}',
        operationId: 'delete-env-by-database-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Databases'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster database.', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'env_uuid', in: 'path', description: 'UUID of the environment variable.', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The environment variable is deleted.', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string')])),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    #[OA\Delete(
        summary: 'Delete Env',
        description: 'Delete an environment variable of a cluster stack.',
        path: '/cluster-stacks/{uuid}/envs/{env_uuid}',
        operationId: 'delete-env-by-stack-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Stacks'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster stack.', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'env_uuid', in: 'path', description: 'UUID of the environment variable.', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'service', in: 'query', description: 'The service of the stack.', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The environment variable is deleted.', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string')])),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function destroy(Request $request): JsonResponse
    {
        $workload = $this->envWorkload($request);
        if ($workload instanceof JsonResponse) {
            return $workload;
        }
        $this->authorize('manageEnvironment', $workload);
        $env = EnvironmentVariable::query()
            ->where('uuid', (string) $request->route('env_uuid'))
            ->where('resourceable_type', $workload->getMorphClass())
            ->where('resourceable_id', $workload->id)
            ->first();
        if ($env === null) {
            return response()->json(['message' => 'Environment variable not found.'], 404);
        }
        $this->audit('api.cluster.env_deleted', $workload, $env);
        $env->forceDelete();

        return response()->json(['message' => 'Environment variable deleted.']);
    }

    /** The workload of a change: the application or database, or the named service of a stack. */
    private function envWorkload(Request $request): NodeWorkload|JsonResponse
    {
        if (! $this->isStack($request)) {
            $workloads = $this->envWorkloads($request, null);

            return $workloads instanceof JsonResponse ? $workloads : $workloads->first();
        }
        $service = (string) $request->input('service', $request->query('service', ''));
        if ($service === '') {
            return response()->json(['message' => 'Validation failed.', 'errors' => ['service' => ['The service field is required.']]], 422);
        }
        $workloads = $this->envWorkloads($request, $service);

        return $workloads instanceof JsonResponse ? $workloads : $workloads->first();
    }

    /** @return Collection<int, NodeWorkload>|JsonResponse */
    private function envWorkloads(Request $request, ?string $service): Collection|JsonResponse
    {
        if (! $this->isStack($request)) {
            $kind = $request->route('cluster_kind') === 'database' ? 'database' : 'application';
            $workload = $this->clusterWorkload($request, $kind);

            return $workload === null
                ? response()->json(['message' => $kind === 'database' ? 'Cluster database not found.' : 'Cluster application not found.'], 404)
                : collect([$workload]);
        }
        $stack = $this->clusterStack($request);
        if ($stack === null) {
            return response()->json(['message' => 'Cluster stack not found.'], 404);
        }
        $workloads = $stack->workloads()->when(filled($service), fn ($query) => $query->where('stack_service', $service))->orderBy('stack_service')->get();
        if (filled($service) && $workloads->isEmpty()) {
            return response()->json(['message' => 'Service not found.'], 404);
        }

        return $workloads;
    }

    private function isStack(Request $request): bool
    {
        return $request->route('cluster_kind') === 'stack';
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|JsonResponse
     */
    private function validatedItem(array $item): array|JsonResponse
    {
        if (array_key_exists('key', $item)) {
            $item['key'] = ValidationPatterns::normalizeEnvironmentVariableKey((string) $item['key']);
        }
        $validator = customApiValidator($item, [
            'key' => ValidationPatterns::environmentVariableKeyRules(),
            'value' => 'string|nullable',
            'is_literal' => 'boolean',
            'is_multiline' => 'boolean',
            'is_shown_once' => 'boolean',
            'is_buildtime' => 'boolean',
            'is_runtime' => 'boolean',
            'comment' => 'string|nullable|max:256',
        ]);
        $extraFields = array_diff(array_keys($item), self::FIELDS);
        if ($validator->fails() || $extraFields !== []) {
            $errors = $validator->errors();
            foreach ($extraFields as $field) {
                $errors->add($field, 'This field is not allowed.');
            }

            return response()->json(['message' => 'Validation failed.', 'errors' => $errors], 422);
        }
        $item = Arr::only($item, self::FIELDS);
        $item['key'] = str($item['key'])->trim()->replace(' ', '_')->value();

        return $item;
    }

    /** @return array<string, mixed> Like the v4 API: the value only for a token that may read sensitive data, never a locked value. */
    private function envResponse(NodeWorkload $workload, EnvironmentVariable $env): array
    {
        $env->makeHidden(['id', 'resourceable', 'resourceable_id', 'resourceable_type']);
        if ($this->canReadClusterSecrets($workload) && ! $env->is_shown_once) {
            $env->makeVisible(['value', 'real_value']);
        } else {
            $env->makeHidden(['value', 'real_value']);
        }

        return [
            ...serializeApiResponse($env)->all(),
            ...($workload->node_stack_id !== null ? ['service' => $workload->stack_service] : []),
        ];
    }

    private function audit(string $event, NodeWorkload $workload, EnvironmentVariable $env): void
    {
        auditLog($event, ['team_id' => $workload->team_id, 'workload_uuid' => $workload->uuid, 'env_uuid' => $env->uuid, 'env_key' => $env->key]);
    }
}
