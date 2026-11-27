<?php

namespace App\Http\Controllers\Api;

use App\Actions\Node\AssignNodeToCluster;
use App\Actions\Node\CleanupNodeImages;
use App\Actions\Node\CreateNodeCluster;
use App\Actions\Node\DeleteNode;
use App\Actions\Node\DeleteNodeCluster;
use App\Actions\Node\ManageNodeClusterNetwork;
use App\Actions\Node\ManageNodeClusterSettings;
use App\Actions\Node\RemoveNodeFromCluster;
use App\Actions\Node\SetNodeBuilder;
use App\Actions\Node\SetNodeBuildLimits;
use App\Actions\Node\SetNodeIngress;
use App\Actions\Node\UpgradeSentinel;
use App\Http\Controllers\Api\Concerns\HandlesClusterResourcesApi;
use App\Http\Controllers\Controller;
use App\Jobs\UpgradeNodeSentinelJob;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\NodeFirewallRule;
use App\Models\Team;
use App\Rules\PrivateIpv4Cidr;
use DomainException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

/**
 * Clusters and their servers (Nodes), the settings of a cluster, and the admin actions of the
 * Node page (decision 0029). A cluster and a Node need team-wide read access, like the UI. A
 * change needs a team admin (`update` on the cluster). No response shows a credential value.
 */
class ClustersController extends Controller
{
    use HandlesClusterResourcesApi;

    #[OA\Get(
        summary: 'List',
        description: 'List the clusters of the team.',
        path: '/clusters',
        operationId: 'list-clusters',
        security: [['bearerAuth' => []]],
        tags: ['Clusters'],
        responses: [
            new OA\Response(response: 200, description: 'The clusters.', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/Cluster'))),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        return response()->json(NodeCluster::query()->where('team_id', getTeamIdFromToken())->with('nodes')->orderBy('id')->get()
            ->filter(fn (NodeCluster $cluster): bool => $request->user()->can('view', $cluster))
            ->map(fn (NodeCluster $cluster): array => $this->clusterResponse($cluster))
            ->values());
    }

    #[OA\Get(
        summary: 'Get',
        description: 'Get a cluster with its servers.',
        path: '/clusters/{uuid}',
        operationId: 'get-cluster-by-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Clusters'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster.', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The cluster.', content: new OA\JsonContent(ref: '#/components/schemas/Cluster')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function show(Request $request): JsonResponse
    {
        $cluster = NodeCluster::query()->where('team_id', getTeamIdFromToken())->where('uuid', (string) $request->route('uuid'))->first();
        if ($cluster === null || $request->user()->cannot('view', $cluster)) {
            return response()->json(['message' => 'Cluster not found.'], 404);
        }

        return response()->json($this->clusterResponse($cluster));
    }

    #[OA\Get(
        summary: 'List Nodes',
        description: 'List the servers of the clusters of the team.',
        path: '/nodes',
        operationId: 'list-nodes',
        security: [['bearerAuth' => []]],
        tags: ['Clusters'],
        responses: [
            new OA\Response(response: 200, description: 'The servers.', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/Node'))),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
        ]
    )]
    public function nodes(Request $request): JsonResponse
    {
        return response()->json(Node::query()->where('team_id', getTeamIdFromToken())->with('cluster')->orderBy('id')->get()
            ->filter(fn (Node $node): bool => $request->user()->can('view', $node))
            ->map(fn (Node $node): array => $this->nodeResponse($node))
            ->values());
    }

    #[OA\Get(
        summary: 'Get Node',
        description: 'Get a server of a cluster.',
        path: '/nodes/{uuid}',
        operationId: 'get-node-by-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Clusters'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the server.', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The server.', content: new OA\JsonContent(ref: '#/components/schemas/Node')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function node(Request $request): JsonResponse
    {
        $node = $this->findNode($request);
        if ($node === null) {
            return response()->json(['message' => 'Node not found.'], 404);
        }

        return response()->json($this->nodeResponse($node));
    }

    #[OA\Patch(
        summary: 'Set Node Roles',
        description: 'Turn ingress or builds on or off for a server of a cluster, like the Servers tab of the cluster page. Needs a team admin.',
        path: '/nodes/{uuid}/roles',
        operationId: 'update-node-roles',
        security: [['bearerAuth' => []]],
        tags: ['Clusters'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the server.', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            type: 'object',
            properties: [
                'is_ingress' => new OA\Property(property: 'is_ingress', type: 'boolean'),
                'is_builder' => new OA\Property(property: 'is_builder', type: 'boolean'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'The server.', content: new OA\JsonContent(ref: '#/components/schemas/Node')),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Not an admin or owner of the team.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function roles(Request $request): JsonResponse
    {
        $node = $this->findNode($request);
        if ($node === null || $node->cluster === null) {
            return response()->json(['message' => 'Node not found.'], 404);
        }
        $this->authorize('update', $node->cluster);
        $invalid = $this->validateRequest($request, ['is_ingress' => 'boolean', 'is_builder' => 'boolean']);
        if ($invalid !== null) {
            return $invalid;
        }

        return $this->runClusterAction(function () use ($request, $node): JsonResponse {
            if ($request->has('is_ingress') && $request->boolean('is_ingress') !== (bool) $node->is_ingress) {
                SetNodeIngress::run($node->cluster, $node, $request->boolean('is_ingress'), $request->user());
            }
            if ($request->has('is_builder') && $request->boolean('is_builder') !== (bool) $node->is_builder) {
                SetNodeBuilder::run($node->cluster, $node, $request->boolean('is_builder'), $request->user());
            }

            return response()->json($this->nodeResponse($node->refresh()));
        });
    }

    #[OA\Patch(
        summary: 'Set Node Build Limits',
        description: 'Set the concurrent builds and the BuildKit limits of a builder server, like the Builds section of the Node page. An empty limit uses the default: 50% of the server memory, no CPU limit, and 20% of the root disk for the build cache. Needs a team admin.',
        path: '/nodes/{uuid}/builds',
        operationId: 'update-node-builds',
        security: [['bearerAuth' => []]],
        tags: ['Clusters'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the server.', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            type: 'object',
            properties: [
                'concurrent_builds' => new OA\Property(property: 'concurrent_builds', type: 'integer', minimum: 1, maximum: 16, description: 'The builds that the server runs at the same time. The default is 1. A value above 1 needs a Sentinel with `image.build.concurrent.v1`.'),
                'memory_limit_gb' => new OA\Property(property: 'memory_limit_gb', type: 'number', nullable: true, description: 'The memory limit of BuildKit in GB. Null: 50% of the server memory.'),
                'cpu_limit' => new OA\Property(property: 'cpu_limit', type: 'number', nullable: true, description: 'The CPU limit of BuildKit in CPUs. Null: no limit.'),
                'cache_gb' => new OA\Property(property: 'cache_gb', type: 'integer', nullable: true, description: 'The build cache size in GB. Null: 20% of the root disk.'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'The server.', content: new OA\JsonContent(ref: '#/components/schemas/Node')),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Not an admin or owner of the team.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function builds(Request $request): JsonResponse
    {
        $node = $this->findNode($request);
        if ($node === null) {
            return response()->json(['message' => 'Node not found.'], 404);
        }
        $this->authorize('update', $node);
        $invalid = $this->validateRequest($request, [
            'concurrent_builds' => 'integer',
            'memory_limit_gb' => 'nullable|numeric',
            'cpu_limit' => 'nullable|numeric',
            'cache_gb' => 'nullable|integer',
        ]);
        if ($invalid !== null) {
            return $invalid;
        }

        return $this->runClusterAction(fn (): JsonResponse => response()->json($this->nodeResponse(
            SetNodeBuildLimits::run($node, $request->only(['concurrent_builds', 'memory_limit_gb', 'cpu_limit', 'cache_gb']), $request->user())
        )));
    }

    #[OA\Post(
        summary: 'Clean Up Node Images',
        description: 'Queue an image cleanup on a server now, like the button on the Node page. It waits while a build or a deploy runs on the server. Needs a team admin.',
        path: '/nodes/{uuid}/image-cleanup',
        operationId: 'cleanup-node-images',
        security: [['bearerAuth' => []]],
        tags: ['Clusters'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the server.', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The cleanup is queued.', content: new OA\JsonContent(type: 'object', properties: [
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'operation_uuid', type: 'string'),
            ])),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Not an admin or owner of the team.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function cleanupImages(Request $request): JsonResponse
    {
        $node = $this->findNode($request);
        if ($node === null) {
            return response()->json(['message' => 'Node not found.'], 404);
        }
        $this->authorize('update', $node);

        return $this->runClusterAction(function () use ($request, $node): JsonResponse {
            $operation = CleanupNodeImages::start($node, $request->user(), 'manual');

            return response()->json([
                'message' => $operation->wasRecentlyCreated ? 'Image cleanup queued.' : 'An image cleanup is already queued or running.',
                'operation_uuid' => $operation->uuid,
            ]);
        });
    }

    #[OA\Post(
        summary: 'Upgrade Sentinel',
        description: 'Upgrade Sentinel on a server to the version of this Coolify, like the button on the Node page. Needs a team admin.',
        path: '/nodes/{uuid}/sentinel/upgrade',
        operationId: 'upgrade-node-sentinel',
        security: [['bearerAuth' => []]],
        tags: ['Clusters'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the server.', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The upgrade is queued.', content: new OA\JsonContent(type: 'object', properties: [
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'operation_uuid', type: 'string'),
            ])),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Not an admin or owner of the team.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function upgradeSentinel(Request $request): JsonResponse
    {
        $node = $this->findNode($request);
        if ($node === null) {
            return response()->json(['message' => 'Node not found.'], 404);
        }
        $this->authorize('manageSentinel', $node);

        return $this->runClusterAction(function () use ($request, $node): JsonResponse {
            $operation = UpgradeSentinel::make()->start($node, $request->user());
            UpgradeNodeSentinelJob::dispatch($operation->id);

            return response()->json(['message' => 'Sentinel upgrade queued.', 'operation_uuid' => $operation->uuid]);
        });
    }

    #[OA\Post(
        summary: 'Create',
        description: 'Create a cluster, like the Clusters page. Without `cidr`, Coolify selects a free range. Needs a team admin. A server joins the cluster with `POST /clusters/{uuid}/servers`.',
        path: '/clusters',
        operationId: 'create-cluster',
        security: [['bearerAuth' => []]],
        tags: ['Clusters'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['name'], properties: [
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'description', type: 'string', nullable: true),
            new OA\Property(property: 'cidr', type: 'string', nullable: true, description: 'A private IPv4 range, for example 10.240.0.0/24.'),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'The cluster.', content: new OA\JsonContent(ref: '#/components/schemas/Cluster')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Not an admin or owner of the team.'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $team = Team::query()->findOrFail(getTeamIdFromToken());
        abort_unless($request->user()->isAdminOfTeam($team->id), 403, 'Not an admin or owner of the team.');
        $invalid = $this->validateRequest($request, [
            'name' => ['required', 'string', 'max:255', Rule::unique('node_clusters')->where('team_id', $team->id)],
            'description' => ['nullable', 'string', 'max:1000'],
            'cidr' => ['nullable', 'string', 'max:64', new PrivateIpv4Cidr],
        ]);
        if ($invalid !== null) {
            return $invalid;
        }

        return $this->runClusterAction(function () use ($request, $team): JsonResponse {
            try {
                $cluster = CreateNodeCluster::run($team, $request->user(), (string) $request->input('name'), blank($request->input('description')) ? null : (string) $request->input('description'), blank($request->input('cidr')) ? null : (string) $request->input('cidr'));
            } catch (DomainException $exception) {
                throw ValidationException::withMessages(['cidr' => $exception->getMessage()]);
            }
            auditLog('api.cluster.created', ['team_id' => $team->id, 'cluster_uuid' => $cluster->uuid, 'cluster_name' => $cluster->name]);

            return response()->json($this->clusterResponse($cluster->refresh()->load('nodes')), 201);
        });
    }

    #[OA\Patch(
        summary: 'Update',
        description: 'Change the settings of a cluster, like the cluster pages: the name and the network (General), and the deployment pressure policy and the image storage (Advanced). Send only the fields to change. Coolify saves the groups in this order and stops at the first error: general, pressure policy, image storage. Needs a team admin. The network fields cannot change on an active cluster, and the CIDR cannot change while the cluster has servers.',
        path: '/clusters/{uuid}',
        operationId: 'update-cluster-by-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Clusters'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster.', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', properties: [
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'description', type: 'string', nullable: true),
            new OA\Property(property: 'cidr', type: 'string'),
            new OA\Property(property: 'wireguard_interface', type: 'string'),
            new OA\Property(property: 'wireguard_port', type: 'integer'),
            new OA\Property(property: 'cpu_pressure_threshold', type: 'integer', description: 'Percent, 1 to 100.'),
            new OA\Property(property: 'memory_pressure_threshold', type: 'integer', description: 'Percent, 1 to 100.'),
            new OA\Property(property: 'disk_pressure_threshold', type: 'integer', description: 'Percent, 1 to 100.'),
            new OA\Property(property: 'resource_stale_after_minutes', type: 'integer', description: '1 to 60.'),
            new OA\Property(property: 'image_storage', type: 'string', enum: ['local', 'external'], description: '`local`: builds stay on the servers, and each server copies an image from a server that has it. `external`: a registry of the team.'),
            new OA\Property(property: 'image_repository', type: 'string', nullable: true, description: 'For `external`: a repository without a tag, for example ghcr.io/acme/coolify-builds.'),
            new OA\Property(property: 'image_registry_credential_uuid', type: 'string', nullable: true, description: 'For `external`: a registry credential of the team for the registry of the repository.'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'The cluster.', content: new OA\JsonContent(ref: '#/components/schemas/Cluster')),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Not an admin or owner of the team.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function update(Request $request): JsonResponse
    {
        $cluster = $this->findCluster($request);
        if ($cluster === null) {
            return response()->json(['message' => 'Cluster not found.'], 404);
        }
        $this->authorize('update', $cluster);
        $invalid = $this->validateRequest($request, [
            'name' => 'sometimes|string|max:255',
            'description' => 'sometimes|nullable|string|max:1000',
            'cidr' => 'sometimes|string',
            'wireguard_interface' => 'sometimes|string',
            'wireguard_port' => 'sometimes|integer',
            'cpu_pressure_threshold' => 'sometimes|integer',
            'memory_pressure_threshold' => 'sometimes|integer',
            'disk_pressure_threshold' => 'sometimes|integer',
            'resource_stale_after_minutes' => 'sometimes|integer',
            'image_storage' => 'sometimes|string',
            'image_repository' => 'sometimes|nullable|string',
            'image_registry_credential_uuid' => 'sometimes|nullable|string',
        ]);
        if ($invalid !== null) {
            return $invalid;
        }
        if ($request->all() === []) {
            return response()->json(['message' => 'At least one field must be provided.'], 422);
        }

        return $this->runClusterAction(function () use ($request, $cluster): JsonResponse {
            $settings = ManageNodeClusterSettings::make();
            $user = $request->user();
            if ($request->hasAny(['name', 'description', 'cidr', 'wireguard_interface', 'wireguard_port'])) {
                $cluster = $settings->updateSettings($cluster, [
                    'name' => $request->input('name', $cluster->name),
                    'description' => $request->has('description') ? $request->input('description') : $cluster->description,
                    'cidr' => $request->input('cidr', $cluster->cidr),
                    'wireguard_interface' => $request->input('wireguard_interface', $cluster->wireguard_interface),
                    'wireguard_port' => (int) $request->input('wireguard_port', $cluster->wireguard_port),
                ], $user);
            }
            if ($request->hasAny(['cpu_pressure_threshold', 'memory_pressure_threshold', 'disk_pressure_threshold', 'resource_stale_after_minutes'])) {
                $cluster = $settings->updatePressurePolicy($cluster, [
                    'cpu_pressure_threshold' => $request->input('cpu_pressure_threshold', $cluster->cpu_pressure_threshold),
                    'memory_pressure_threshold' => $request->input('memory_pressure_threshold', $cluster->memory_pressure_threshold),
                    'disk_pressure_threshold' => $request->input('disk_pressure_threshold', $cluster->disk_pressure_threshold),
                    'resource_stale_after_minutes' => $request->input('resource_stale_after_minutes', $cluster->resource_stale_after_minutes),
                ], $user);
            }
            if ($request->hasAny(['image_storage', 'image_repository', 'image_registry_credential_uuid'])) {
                $settings->updateImageStorage(
                    $cluster,
                    $request->input('image_storage', $cluster->image_storage?->value),
                    $request->has('image_repository') ? $request->input('image_repository') : $cluster->image_repository,
                    $request->has('image_registry_credential_uuid') ? $request->input('image_registry_credential_uuid') : $cluster->imageRegistryCredential?->uuid,
                    $user,
                );
            }
            auditLog('api.cluster.updated', ['team_id' => $cluster->team_id, 'cluster_uuid' => $cluster->uuid, 'fields' => array_keys($request->all())]);

            return response()->json($this->clusterResponse($cluster->refresh()->load('nodes')));
        });
    }

    #[OA\Delete(
        summary: 'Delete',
        description: 'Delete a cluster. Its servers leave the cluster first. Remove every workload from the cluster before. Needs a team admin.',
        path: '/clusters/{uuid}',
        operationId: 'delete-cluster-by-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Clusters'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster.', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The cluster is deleted.', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string')])),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Not an admin or owner of the team.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function destroy(Request $request): JsonResponse
    {
        $cluster = $this->findCluster($request);
        if ($cluster === null) {
            return response()->json(['message' => 'Cluster not found.'], 404);
        }
        $this->authorize('delete', $cluster);

        return $this->runClusterAction(function () use ($request, $cluster): JsonResponse {
            DeleteNodeCluster::run($cluster, $request->user());
            auditLog('api.cluster.deleted', ['team_id' => $cluster->team_id, 'cluster_uuid' => $cluster->uuid, 'cluster_name' => $cluster->name]);

            return response()->json(['message' => 'Cluster deleted.']);
        });
    }

    #[OA\Post(
        summary: 'Add Server',
        description: 'Add a server of the team that is in no cluster to a cluster, like the Servers tab of the cluster page. Coolify then sets up the network of the cluster on it. Needs a team admin.',
        path: '/clusters/{uuid}/servers',
        operationId: 'add-cluster-server',
        security: [['bearerAuth' => []]],
        tags: ['Clusters'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster.', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['server_uuid'], properties: [new OA\Property(property: 'server_uuid', type: 'string')])),
        responses: [
            new OA\Response(response: 200, description: 'The cluster.', content: new OA\JsonContent(ref: '#/components/schemas/Cluster')),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Not an admin or owner of the team.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function addServer(Request $request): JsonResponse
    {
        $cluster = $this->findCluster($request);
        if ($cluster === null) {
            return response()->json(['message' => 'Cluster not found.'], 404);
        }
        $this->authorize('update', $cluster);
        $invalid = $this->validateRequest($request, ['server_uuid' => 'required|string']);
        if ($invalid !== null) {
            return $invalid;
        }
        $node = Node::query()->where('team_id', $cluster->team_id)->whereNull('node_cluster_id')->where('uuid', (string) $request->input('server_uuid'))->first();
        if ($node === null) {
            return response()->json(['message' => 'Validation failed.', 'errors' => ['server_uuid' => ['Select a server of the team that is in no cluster.']]], 422);
        }

        return $this->runClusterAction(function () use ($request, $cluster, $node): JsonResponse {
            AssignNodeToCluster::run($cluster, $node, $request->user());

            return response()->json($this->clusterResponse($cluster->refresh()->load('nodes')));
        });
    }

    #[OA\Delete(
        summary: 'Remove Server',
        description: 'Remove a server from a cluster, like the Servers tab of the cluster page. Move or delete its workloads first. Needs a team admin.',
        path: '/clusters/{uuid}/servers/{server_uuid}',
        operationId: 'remove-cluster-server',
        security: [['bearerAuth' => []]],
        tags: ['Clusters'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster.', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'server_uuid', in: 'path', description: 'UUID of the server.', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The cluster.', content: new OA\JsonContent(ref: '#/components/schemas/Cluster')),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Not an admin or owner of the team.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function removeServer(Request $request): JsonResponse
    {
        $cluster = $this->findCluster($request);
        if ($cluster === null) {
            return response()->json(['message' => 'Cluster not found.'], 404);
        }
        $this->authorize('update', $cluster);
        $node = $cluster->nodes()->where('team_id', $cluster->team_id)->where('uuid', (string) $request->route('server_uuid'))->first();
        if ($node === null) {
            return response()->json(['message' => 'Server not found in this cluster.'], 404);
        }

        return $this->runClusterAction(function () use ($request, $cluster, $node): JsonResponse {
            RemoveNodeFromCluster::run($cluster, $node, $request->user());

            return response()->json($this->clusterResponse($cluster->refresh()->load('nodes')));
        });
    }

    #[OA\Get(
        summary: 'List Firewall Rules',
        description: 'List the firewall rules of a cluster. A rule lets a workload or a server reach a workload of the mesh on one port.',
        path: '/clusters/{uuid}/firewall-rules',
        operationId: 'list-cluster-firewall-rules',
        security: [['bearerAuth' => []]],
        tags: ['Clusters'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster.', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The rules.', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/ClusterFirewallRule'))),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function firewallRules(Request $request): JsonResponse
    {
        $cluster = $this->findCluster($request);
        if ($cluster === null) {
            return response()->json(['message' => 'Cluster not found.'], 404);
        }

        return response()->json(NodeFirewallRule::query()
            ->with(['sourceWorkload', 'sourceNode', 'destinationWorkload'])
            ->where('node_cluster_id', $cluster->id)
            ->orderBy('id')
            ->get()
            ->map(fn (NodeFirewallRule $rule): array => $this->firewallRuleResponse($rule))
            ->values());
    }

    #[OA\Post(
        summary: 'Add Firewall Rule',
        description: 'Let a workload or a server of the mesh reach a workload on one port, like the Firewall tab of the cluster page. Coolify then reconciles the network. Needs a team admin.',
        path: '/clusters/{uuid}/firewall-rules',
        operationId: 'add-cluster-firewall-rule',
        security: [['bearerAuth' => []]],
        tags: ['Clusters'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster.', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['source_type', 'source_uuid', 'destination_uuid', 'protocol'], properties: [
            new OA\Property(property: 'source_type', type: 'string', enum: ['workload', 'node']),
            new OA\Property(property: 'source_uuid', type: 'string', description: 'A workload in the mesh, or a server of the cluster.'),
            new OA\Property(property: 'destination_uuid', type: 'string', description: 'A workload in the mesh.'),
            new OA\Property(property: 'protocol', type: 'string', enum: ['tcp', 'udp', 'icmp']),
            new OA\Property(property: 'port', type: 'integer', description: 'Required for `tcp` and `udp`.'),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'The rule is added.', content: new OA\JsonContent(ref: '#/components/schemas/ClusterFirewallRule')),
            new OA\Response(response: 200, description: 'The rule exists already.', content: new OA\JsonContent(ref: '#/components/schemas/ClusterFirewallRule')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Not an admin or owner of the team.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function addFirewallRule(Request $request): JsonResponse
    {
        $cluster = $this->findCluster($request);
        if ($cluster === null) {
            return response()->json(['message' => 'Cluster not found.'], 404);
        }
        $this->authorize('update', $cluster);
        $invalid = $this->validateRequest($request, [
            'source_type' => 'required|string',
            'source_uuid' => 'required|string',
            'destination_uuid' => 'required|string',
            'protocol' => 'required|string',
            'port' => 'sometimes|integer',
        ]);
        if ($invalid !== null) {
            return $invalid;
        }

        return $this->runClusterAction(function () use ($request, $cluster): JsonResponse {
            try {
                $result = ManageNodeClusterSettings::make()->addFirewallRule($cluster, (string) $request->input('source_type'), (string) $request->input('source_uuid'), (string) $request->input('destination_uuid'), (string) $request->input('protocol'), (int) $request->input('port', 0), $request->user());
            } catch (ValidationException $exception) {
                $fields = ['source' => 'source_uuid', 'destination' => 'destination_uuid'];
                throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn (array $messages, string $key): array => [$fields[$key] ?? $key => $messages])->all());
            }

            return response()->json($this->firewallRuleResponse($result['rule']->load(['sourceWorkload', 'sourceNode', 'destinationWorkload'])), $result['created'] ? 201 : 200);
        });
    }

    #[OA\Delete(
        summary: 'Delete Firewall Rule',
        description: 'Delete a firewall rule of a cluster. Coolify then reconciles the network. Needs a team admin.',
        path: '/clusters/{uuid}/firewall-rules/{rule_uuid}',
        operationId: 'delete-cluster-firewall-rule',
        security: [['bearerAuth' => []]],
        tags: ['Clusters'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster.', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'rule_uuid', in: 'path', description: 'UUID of the rule.', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The rule is deleted.', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string')])),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Not an admin or owner of the team.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function deleteFirewallRule(Request $request): JsonResponse
    {
        $cluster = $this->findCluster($request);
        if ($cluster === null) {
            return response()->json(['message' => 'Cluster not found.'], 404);
        }
        $this->authorize('update', $cluster);
        $rule = NodeFirewallRule::query()->where('node_cluster_id', $cluster->id)->where('uuid', (string) $request->route('rule_uuid'))->first();
        if ($rule === null) {
            return response()->json(['message' => 'Firewall rule not found.'], 404);
        }

        return $this->runClusterAction(function () use ($request, $cluster, $rule): JsonResponse {
            ManageNodeClusterSettings::make()->removeFirewallRule($cluster, $rule, $request->user());

            return response()->json(['message' => 'Firewall rule deleted.']);
        });
    }

    #[OA\Post(
        summary: 'Reconcile Network',
        description: 'Queue a reconciliation of the network of a cluster, like the button on the cluster page. Needs a team admin.',
        path: '/clusters/{uuid}/network/reconcile',
        operationId: 'reconcile-cluster-network',
        security: [['bearerAuth' => []]],
        tags: ['Clusters'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster.', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The reconciliation is queued.', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'network_status', type: 'string')])),
            new OA\Response(response: 400, description: 'A reconciliation runs already, or the cluster has no server.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Not an admin or owner of the team.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function reconcileNetwork(Request $request): JsonResponse
    {
        $cluster = $this->findCluster($request);
        if ($cluster === null) {
            return response()->json(['message' => 'Cluster not found.'], 404);
        }
        $this->authorize('update', $cluster);

        return $this->runClusterAction(function () use ($request, $cluster): JsonResponse {
            if (! ManageNodeClusterNetwork::make()->reconcile($cluster, $request->user())) {
                return response()->json(['message' => 'Cluster network reconciliation is already running.'], 400);
            }
            auditLog('api.cluster.network_reconcile', ['team_id' => $cluster->team_id, 'cluster_uuid' => $cluster->uuid]);

            return response()->json(['message' => 'Cluster network reconciliation queued.', 'network_status' => $cluster->refresh()->network_status]);
        });
    }

    #[OA\Post(
        summary: 'Repair Network',
        description: 'Restore the last good network state on each server of a cluster over SSH, like the button on the cluster page. The call waits until each server is done. Reconcile the network after it to verify the state. Needs a team admin.',
        path: '/clusters/{uuid}/network/repair',
        operationId: 'repair-cluster-network',
        security: [['bearerAuth' => []]],
        tags: ['Clusters'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster.', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The network state is restored.', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'network_status', type: 'string')])),
            new OA\Response(response: 400, description: 'A reconciliation runs, or a server could not be repaired.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Not an admin or owner of the team.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function repairNetwork(Request $request): JsonResponse
    {
        $cluster = $this->findCluster($request);
        if ($cluster === null) {
            return response()->json(['message' => 'Cluster not found.'], 404);
        }
        $this->authorize('update', $cluster);

        return $this->runClusterAction(function () use ($request, $cluster): JsonResponse {
            try {
                ManageNodeClusterNetwork::make()->repair($cluster, $request->user());
            } catch (LockTimeoutException $exception) {
                return response()->json(['message' => $exception->getMessage()], 400);
            }
            auditLog('api.cluster.network_repair', ['team_id' => $cluster->team_id, 'cluster_uuid' => $cluster->uuid]);

            return response()->json(['message' => 'Cluster network state restored over SSH. Reconcile the network to verify current state.', 'network_status' => $cluster->refresh()->network_status]);
        });
    }

    #[OA\Post(
        summary: 'Upgrade Sentinel',
        description: 'Upgrade Sentinel on each server of a cluster that runs an older version, one server at a time, like the button on the cluster page. The upgrade stops at the first failure. Needs a team admin.',
        path: '/clusters/{uuid}/sentinel/upgrade',
        operationId: 'upgrade-cluster-sentinel',
        security: [['bearerAuth' => []]],
        tags: ['Clusters'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster.', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The upgrade is queued.', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'server_uuids', type: 'array', items: new OA\Items(type: 'string'))])),
            new OA\Response(response: 400, description: 'Sentinel is up to date on each server, or an upgrade runs already.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Not an admin or owner of the team.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function upgradeClusterSentinel(Request $request): JsonResponse
    {
        $cluster = $this->findCluster($request);
        if ($cluster === null) {
            return response()->json(['message' => 'Cluster not found.'], 404);
        }
        $this->authorize('update', $cluster);

        return $this->runClusterAction(function () use ($request, $cluster): JsonResponse {
            $nodes = ManageNodeClusterNetwork::make()->upgradeSentinel($cluster, $request->user());
            if ($nodes->isEmpty()) {
                return response()->json(['message' => 'Sentinel is up to date on every server of this cluster.'], 400);
            }
            auditLog('api.cluster.sentinel_upgrade', ['team_id' => $cluster->team_id, 'cluster_uuid' => $cluster->uuid]);

            return response()->json(['message' => 'Sentinel upgrade queued. Servers are upgraded one at a time.', 'server_uuids' => $nodes->pluck('uuid')->values()->all()]);
        });
    }

    #[OA\Delete(
        summary: 'Delete Node',
        description: 'Delete a server (Node) from Coolify, like the Danger zone of the Node page. The server must be in no cluster and run no applications. Coolify runs no command on the server: Sentinel and the data stay on it. A server that still holds volumes or volume copies is deleted only with `force=true`, for a server that is gone for good: Coolify cannot remove that data after the delete. Needs a team admin.',
        path: '/nodes/{uuid}',
        operationId: 'delete-node-by-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Clusters'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the Node.', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'force', in: 'query', description: 'Delete also when the server holds volumes or volume copies. The data stays on the server. The default is false.', required: false, schema: new OA\Schema(type: 'boolean', default: false)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The Node is deleted.', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string')])),
            new OA\Response(response: 400, description: 'The Node is in a cluster or runs applications, or it holds volume data and `force` is not set.', content: new OA\JsonContent(type: 'object', properties: [
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'volumes', type: 'integer', description: 'Only for volume data.'),
                new OA\Property(property: 'volume_copies', type: 'integer', description: 'Only for volume data.'),
            ])),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Not an admin or owner of the team.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function destroyNode(Request $request): JsonResponse
    {
        $node = $this->findNode($request);
        if ($node === null) {
            return response()->json(['message' => 'Node not found.'], 404);
        }
        $this->authorize('delete', $node);

        $force = filter_var($request->query('force', false), FILTER_VALIDATE_BOOLEAN);
        ['volumes' => $volumes, 'copies' => $copies] = DeleteNode::dataOn($node);
        if (! $force && $node->node_cluster_id === null && ! $node->workloads()->exists() && $volumes + $copies > 0) {
            return response()->json(['message' => DeleteNode::dataError($volumes, $copies), 'volumes' => $volumes, 'volume_copies' => $copies], 400);
        }

        return $this->runClusterAction(function () use ($request, $node, $force): JsonResponse {
            DeleteNode::run($node, $request->user(), $force, 'api');

            return response()->json(['message' => 'Node deleted.']);
        });
    }

    /** A cluster of the team of the token that the user may view. A hidden cluster is not found. */
    private function findCluster(Request $request): ?NodeCluster
    {
        $cluster = NodeCluster::query()->where('team_id', getTeamIdFromToken())->where('uuid', (string) $request->route('uuid'))->first();

        return $cluster !== null && $request->user()->can('view', $cluster) ? $cluster : null;
    }

    /** @return array<string, mixed> */
    private function firewallRuleResponse(NodeFirewallRule $rule): array
    {
        return [
            'uuid' => $rule->uuid,
            'source_type' => $rule->source_node_id !== null ? 'node' : 'workload',
            'source_uuid' => $rule->sourceNode?->uuid ?? $rule->sourceWorkload?->uuid,
            'source_name' => $rule->sourceNode?->name ?? $rule->sourceWorkload?->name,
            'destination_uuid' => $rule->destinationWorkload?->uuid,
            'destination_name' => $rule->destinationWorkload?->name,
            'protocol' => $rule->protocol,
            'port' => $rule->port,
            'created_at' => $rule->created_at,
        ];
    }

    /** A Node of the team of the token that the user may view. A hidden Node is not found. */
    private function findNode(Request $request): ?Node
    {
        $node = Node::query()->where('team_id', getTeamIdFromToken())->where('uuid', (string) $request->route('uuid'))->first();

        return $node !== null && $request->user()->can('view', $node) ? $node : null;
    }

    /** @return array<string, mixed> */
    private function clusterResponse(NodeCluster $cluster): array
    {
        return [
            'uuid' => $cluster->uuid,
            'name' => $cluster->name,
            'description' => $cluster->description,
            'cidr' => $cluster->cidr,
            'network_status' => $cluster->network_status,
            'last_reconciled_at' => $cluster->last_reconciled_at,
            'wireguard_interface' => $cluster->wireguard_interface,
            'wireguard_port' => $cluster->wireguard_port,
            'cpu_pressure_threshold' => $cluster->cpu_pressure_threshold,
            'memory_pressure_threshold' => $cluster->memory_pressure_threshold,
            'disk_pressure_threshold' => $cluster->disk_pressure_threshold,
            'resource_stale_after_minutes' => $cluster->resource_stale_after_minutes,
            'image_storage' => $cluster->image_storage,
            'image_repository' => $cluster->image_repository,
            'image_registry_credential_uuid' => $cluster->imageRegistryCredential?->uuid,
            'generated_domain_base' => $cluster->generatedDomainBase(),
            'servers' => $cluster->nodes->sortBy('id')->map(fn (Node $node): array => [
                'uuid' => $node->uuid,
                'name' => $node->name,
                'role' => $node->role?->value,
                'is_ingress' => (bool) $node->is_ingress,
                'is_builder' => (bool) $node->is_builder,
                'is_usable' => (bool) $node->is_usable,
            ])->values()->all(),
            'created_at' => $cluster->created_at,
            'updated_at' => $cluster->updated_at,
        ];
    }

    /** @return array<string, mixed> */
    private function nodeResponse(Node $node): array
    {
        return [
            'uuid' => $node->uuid,
            'name' => $node->name,
            'description' => $node->description,
            'ip' => $node->ip,
            'port' => $node->port,
            'user' => $node->user,
            'role' => $node->role?->value,
            'cluster_uuid' => $node->cluster?->uuid,
            'is_reachable' => (bool) $node->is_reachable,
            'is_usable' => (bool) $node->is_usable,
            'is_ingress' => (bool) $node->is_ingress,
            'is_builder' => (bool) $node->is_builder,
            'wireguard_ip' => $node->wireguard_ip,
            'network_status' => $node->network_status,
            'sentinel_version' => $node->sentinel_version,
            'image_cleanup_frequency' => $node->image_cleanup_frequency,
            'image_cleanup_threshold' => $node->image_cleanup_threshold,
            ...SetNodeBuildLimits::values($node),
            'created_at' => $node->created_at,
            'updated_at' => $node->updated_at,
        ];
    }
}
