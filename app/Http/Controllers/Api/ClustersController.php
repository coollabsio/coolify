<?php

namespace App\Http\Controllers\Api;

use App\Actions\Node\CleanupNodeImages;
use App\Actions\Node\SetNodeBuilder;
use App\Actions\Node\SetNodeIngress;
use App\Actions\Node\UpgradeSentinel;
use App\Http\Controllers\Api\Concerns\HandlesClusterResourcesApi;
use App\Http\Controllers\Controller;
use App\Jobs\UpgradeNodeSentinelJob;
use App\Models\Node;
use App\Models\NodeCluster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Clusters and their servers (Nodes), read-only, and the admin actions of the Node page
 * (decision 0029). A cluster and a Node need team-wide read access, like the UI.
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
            'image_storage' => $cluster->image_storage,
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
            'created_at' => $node->created_at,
            'updated_at' => $node->updated_at,
        ];
    }
}
