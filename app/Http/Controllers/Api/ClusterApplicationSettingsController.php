<?php

namespace App\Http\Controllers\Api;

use App\Actions\Node\MoveNodeWorkload;
use App\Actions\Node\PublishNodeWorkloadPort;
use App\Actions\Node\QueueNodeClusterNetworkRevision;
use App\Actions\Node\RemoveNodePublishedPort;
use App\Actions\Node\UpdateNodeWorkloadDomains;
use App\Actions\Node\UpdateNodeWorkloadProxyOptions;
use App\Actions\Node\UpdateNodeWorkloadResources;
use App\Actions\Sentinel\FetchContainerLogs;
use App\Http\Controllers\Api\Concerns\HandlesClusterResourcesApi;
use App\Http\Controllers\Controller;
use App\Models\NodeCluster;
use App\Models\NodePublishedPort;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

/**
 * The settings pages of a cluster application in the API (decision 0029): domains, resource
 * limits, published ports, the move to another server, and logs. Each write calls the action of
 * the page.
 */
class ClusterApplicationSettingsController extends Controller
{
    use HandlesClusterResourcesApi;

    #[OA\Patch(
        summary: 'Update Domains',
        description: 'Replace the domains of a cluster application, or change its redirects and proxy options. Ingress servers apply them without a redeploy. A domain without a scheme gets https. A port in a URL is the port of the container. Basic auth and the IP allow list need Sentinel with `ingress.route_options.v1` on every server of the cluster.',
        path: '/cluster-applications/{uuid}/domains',
        operationId: 'update-cluster-application-domains',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Applications'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster application.', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            type: 'object',
            properties: [
                'domains' => new OA\Property(property: 'domains', type: 'array', items: new OA\Items(type: 'string'), description: 'For example `https://app.example.com` or `http://app.example.com:3000/api`. An empty list removes every domain.'),
                'force_https' => new OA\Property(property: 'force_https', type: 'boolean'),
                'www_redirect' => new OA\Property(property: 'www_redirect', type: 'string', enum: ['both', 'www', 'non-www']),
                'permanent_redirect' => new OA\Property(property: 'permanent_redirect', type: 'boolean'),
                'is_http_basic_auth_enabled' => new OA\Property(property: 'is_http_basic_auth_enabled', type: 'boolean'),
                'http_basic_auth_username' => new OA\Property(property: 'http_basic_auth_username', type: 'string', description: '1 to 64 printable ASCII characters, without `:`.'),
                'http_basic_auth_password' => new OA\Property(property: 'http_basic_auth_password', type: 'string', description: '8 to 72 printable ASCII characters. Coolify sends only a bcrypt hash to the servers.'),
                'ingress_allowed_ips' => new OA\Property(property: 'ingress_allowed_ips', type: 'array', items: new OA\Items(type: 'string'), description: 'At most 32 IP addresses or CIDR ranges. Other addresses get 403. An empty list allows every address.'),
                'ingress_request_headers' => new OA\Property(property: 'ingress_request_headers', type: 'array', items: new OA\Items(ref: '#/components/schemas/ClusterIngressHeader'), description: 'At most 16. Connection, framing, proxy, and forwarding headers are refused, and a value cannot have `{` or `}`.'),
                'ingress_response_headers' => new OA\Property(property: 'ingress_response_headers', type: 'array', items: new OA\Items(ref: '#/components/schemas/ClusterIngressHeader'), description: 'At most 16, with the rules of the request headers.'),
                'is_gzip_enabled' => new OA\Property(property: 'is_gzip_enabled', type: 'boolean'),
                'is_stripprefix_enabled' => new OA\Property(property: 'is_stripprefix_enabled', type: 'boolean'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'The updated cluster application.', content: new OA\JsonContent(ref: '#/components/schemas/ClusterApplication')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function domains(Request $request): JsonResponse
    {
        $workload = $this->clusterWorkload($request, 'application');
        if ($workload === null) {
            return response()->json(['message' => 'Cluster application not found.'], 404);
        }
        $this->authorize('update', $workload);
        $invalid = $this->validateRequest($request, [
            'domains' => 'array|max:100',
            'domains.*' => 'string|max:2048',
            'force_https' => 'boolean',
            'www_redirect' => 'string|in:both,www,non-www',
            'permanent_redirect' => 'boolean',
            'is_http_basic_auth_enabled' => 'boolean',
            'http_basic_auth_username' => 'nullable|string',
            'http_basic_auth_password' => 'nullable|string',
            'ingress_allowed_ips' => 'nullable|array',
            'ingress_allowed_ips.*' => 'string',
            'ingress_request_headers' => 'nullable|array',
            'ingress_request_headers.*.name' => 'required|string',
            'ingress_request_headers.*.value' => 'present|nullable|string',
            'ingress_response_headers' => 'nullable|array',
            'ingress_response_headers.*.name' => 'required|string',
            'ingress_response_headers.*.value' => 'present|nullable|string',
            'is_gzip_enabled' => 'boolean',
            'is_stripprefix_enabled' => 'boolean',
        ]);
        if ($invalid !== null) {
            return $invalid;
        }

        return $this->runClusterAction(function () use ($request, $workload): JsonResponse {
            $user = $request->user();
            $proxyKeys = ['is_http_basic_auth_enabled', 'http_basic_auth_username', 'http_basic_auth_password', 'ingress_allowed_ips', 'ingress_request_headers', 'ingress_response_headers', 'is_gzip_enabled', 'is_stripprefix_enabled'];
            $routes = $request->has('domains')
                ? UpdateNodeWorkloadDomains::routesFromUrls($workload, $request->input('domains', []))
                : ($workload->domain_routes ?? []);
            // Both parts are validated before anything is saved, and saved in one transaction, so a 422 saves nothing.
            $domainValues = UpdateNodeWorkloadDomains::make()->validated($workload, $routes, $user, array_filter([
                'force_https' => $request->has('force_https') ? $request->boolean('force_https') : null,
                'www_redirect' => $request->input('www_redirect'),
                'permanent_redirect' => $request->has('permanent_redirect') ? $request->boolean('permanent_redirect') : null,
            ], fn ($value): bool => $value !== null));
            $proxyValues = $request->hasAny($proxyKeys)
                ? UpdateNodeWorkloadProxyOptions::validated($workload, $request->only($proxyKeys), $user)
                : null;
            $changed = UpdateNodeWorkloadDomains::withLock(fn (): bool => DB::transaction(function () use ($workload, $proxyValues, $domainValues, $user): bool {
                $proxyChanged = $proxyValues !== null && UpdateNodeWorkloadProxyOptions::apply($workload, $proxyValues);

                return UpdateNodeWorkloadDomains::apply($workload, $domainValues, $user) || $proxyChanged;
            }));
            $workload->refresh();
            if ($changed) {
                NodeCluster::query()
                    ->whereKey($workload->clusterIds())
                    ->get()
                    ->each(fn (NodeCluster $cluster) => QueueNodeClusterNetworkRevision::run($cluster, $user));
            }

            return response()->json($this->workloadResponse($workload));
        });
    }

    #[OA\Patch(
        summary: 'Update Resource Limits',
        description: 'Set the CPU and memory limits and reservations of a cluster application. Null removes a value. A running application applies them within about a minute.',
        path: '/cluster-applications/{uuid}/resources',
        operationId: 'update-cluster-application-resources',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Applications'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster application.', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            type: 'object',
            properties: [
                'cpu_limit' => new OA\Property(property: 'cpu_limit', type: 'number', nullable: true, description: 'CPUs, for example 0.5.'),
                'cpu_reservation' => new OA\Property(property: 'cpu_reservation', type: 'number', nullable: true),
                'memory_limit_mb' => new OA\Property(property: 'memory_limit_mb', type: 'integer', nullable: true),
                'memory_reservation_mb' => new OA\Property(property: 'memory_reservation_mb', type: 'integer', nullable: true),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'The updated cluster application.', content: new OA\JsonContent(ref: '#/components/schemas/ClusterApplication')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function resources(Request $request): JsonResponse
    {
        $workload = $this->clusterWorkload($request, 'any');
        if ($workload === null || $workload->isPreview()) {
            return response()->json(['message' => 'Cluster application not found.'], 404);
        }
        $this->authorize('update', $workload);
        $invalid = $this->validateRequest($request, ['cpu_limit' => 'nullable', 'cpu_reservation' => 'nullable', 'memory_limit_mb' => 'nullable', 'memory_reservation_mb' => 'nullable']);
        if ($invalid !== null) {
            return $invalid;
        }
        $current = $this->workloadResponse($workload);

        return $this->runClusterAction(function () use ($request, $workload, $current): JsonResponse {
            $input = [];
            foreach (['cpu_limit', 'cpu_reservation', 'memory_limit_mb', 'memory_reservation_mb'] as $key) {
                $input[$key] = $request->has($key) ? $request->input($key) : $current[$key];
            }
            UpdateNodeWorkloadResources::run($workload, UpdateNodeWorkloadResources::fromInput($input));

            return response()->json($this->workloadResponse($workload->refresh()));
        });
    }

    #[OA\Get(
        summary: 'List Published Ports',
        description: 'List the TCP and UDP ports that every server of the cluster publishes for this application.',
        path: '/cluster-applications/{uuid}/ports',
        operationId: 'list-cluster-application-ports',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Applications'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster application or database.', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The published ports.', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/ClusterPublishedPort'))),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function ports(Request $request): JsonResponse
    {
        $workload = $this->clusterWorkload($request, 'any');
        if ($workload === null) {
            return response()->json(['message' => 'Cluster application not found.'], 404);
        }
        $this->authorize('view', $workload);

        return response()->json($workload->publishedPorts()->get()->map(fn (NodePublishedPort $port): array => $this->portResponse($port))->values());
    }

    #[OA\Post(
        summary: 'Publish Port',
        description: 'Publish a TCP or UDP port of the container on every server of the cluster. The servers open it without a redeploy.',
        path: '/cluster-applications/{uuid}/ports',
        operationId: 'create-cluster-application-port',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Applications'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster application or database.', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            type: 'object',
            required: ['container_port'],
            properties: [
                'protocol' => new OA\Property(property: 'protocol', type: 'string', enum: ['tcp', 'udp'], default: 'tcp'),
                'container_port' => new OA\Property(property: 'container_port', type: 'integer'),
                'host_port' => new OA\Property(property: 'host_port', type: 'integer', description: 'Default: the container port.'),
                'source_cidrs' => new OA\Property(property: 'source_cidrs', type: 'array', items: new OA\Items(type: 'string'), description: 'IPv4 addresses or CIDRs that may connect. Empty allows every source.'),
            ],
        )),
        responses: [
            new OA\Response(response: 201, description: 'The port is published.', content: new OA\JsonContent(ref: '#/components/schemas/ClusterPublishedPort')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function publishPort(Request $request): JsonResponse
    {
        $workload = $this->clusterWorkload($request, 'any');
        if ($workload === null || $workload->isPreview()) {
            return response()->json(['message' => 'Cluster application not found.'], 404);
        }
        $this->authorize('update', $workload);
        $invalid = $this->validateRequest($request, ['protocol' => 'nullable', 'container_port' => 'required', 'host_port' => 'nullable', 'source_cidrs' => 'nullable']);
        if ($invalid !== null) {
            return $invalid;
        }

        return $this->runClusterAction(fn (): JsonResponse => response()->json($this->portResponse(PublishNodeWorkloadPort::run($workload, [
            'protocol' => $request->input('protocol', 'tcp'),
            'container_port' => $request->input('container_port'),
            'host_port' => $request->input('host_port', ''),
            'source_cidrs' => $request->input('source_cidrs', []),
        ], $request->user())), 201));
    }

    #[OA\Delete(
        summary: 'Remove Published Port',
        description: 'Close a published port on every server of the cluster.',
        path: '/cluster-applications/{uuid}/ports/{port_uuid}',
        operationId: 'delete-cluster-application-port',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Applications'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster application or database.', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'port_uuid', in: 'path', description: 'UUID of the published port.', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The port is removed.', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string')])),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function removePort(Request $request): JsonResponse
    {
        $workload = $this->clusterWorkload($request, 'any');
        $port = $workload?->publishedPorts()->where('uuid', (string) $request->route('port_uuid'))->first();
        if ($port === null) {
            return response()->json(['message' => 'Published port not found.'], 404);
        }
        $this->authorize('update', $workload);

        return $this->runClusterAction(function () use ($request, $port): JsonResponse {
            RemoveNodePublishedPort::run($port, $request->user());

            return response()->json(['message' => 'Port removed. The servers of the cluster close it without a redeploy.']);
        });
    }

    #[OA\Post(
        summary: 'Move',
        description: 'Move a cluster application from one of its servers to another server of the same cluster (decision 0014). With volumes, Coolify copies the data first and stops the application only for the final copy. A move from an offline server restores the volumes from their latest backups and needs `recovery_confirmed`.',
        path: '/cluster-applications/{uuid}/move',
        operationId: 'move-cluster-application',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Applications'],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster application or database.', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            type: 'object',
            required: ['target_server_uuid'],
            properties: [
                'source_server_uuid' => new OA\Property(property: 'source_server_uuid', type: 'string', description: 'Default: the first server of the application.'),
                'target_server_uuid' => new OA\Property(property: 'target_server_uuid', type: 'string'),
                'recovery_confirmed' => new OA\Property(property: 'recovery_confirmed', type: 'boolean'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'The move is queued.', content: new OA\JsonContent(type: 'object', properties: [
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'move_uuid', type: 'string'),
                new OA\Property(property: 'recovery', type: 'boolean'),
            ])),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function move(Request $request): JsonResponse
    {
        $workload = $this->clusterWorkload($request, 'any');
        if ($workload === null || $workload->isPreview()) {
            return response()->json(['message' => 'Cluster application not found.'], 404);
        }
        $this->authorize('update', $workload);
        $invalid = $this->validateRequest($request, ['source_server_uuid' => 'string|nullable', 'target_server_uuid' => 'required|string', 'recovery_confirmed' => 'boolean']);
        if ($invalid !== null) {
            return $invalid;
        }
        $source = (string) ($request->input('source_server_uuid') ?: $workload->nodes()->orderBy('nodes.id')->value('nodes.uuid'));

        return $this->runClusterAction(function () use ($request, $workload, $source): JsonResponse {
            $move = MoveNodeWorkload::run($workload, $source, (string) $request->input('target_server_uuid'), $request->user(), $request->boolean('recovery_confirmed'));

            return response()->json([
                'message' => $move['recovery']
                    ? "Recovery move queued. The application starts on {$move['target']->name}. Its old container on {$move['source']->name} is removed when that server comes back."
                    : "Move to {$move['target']->name} queued.",
                'move_uuid' => $move['operation']->uuid,
                'recovery' => $move['recovery'],
                'moves_too' => $move['group']->all(),
            ]);
        });
    }

    #[OA\Get(
        summary: 'Get Logs',
        description: 'Get the latest log lines of the container of a cluster application on one server. Logs can contain secrets, so the token needs `read:sensitive`, and the user needs write access on the application, like the Logs page.',
        path: '/cluster-applications/{uuid}/logs',
        operationId: 'get-cluster-application-logs',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Applications'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster application or database.', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'lines', in: 'query', description: 'Number of lines, from 1 to 10000.', required: false, schema: new OA\Schema(type: 'integer', default: 100)),
            new OA\Parameter(name: 'server_uuid', in: 'query', description: 'The server. Default: the first server of the application.', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The logs.', content: new OA\JsonContent(type: 'object', properties: [
                new OA\Property(property: 'logs', type: 'string'),
                new OA\Property(property: 'truncated', type: 'boolean'),
            ])),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function logs(Request $request): JsonResponse
    {
        $workload = $this->clusterWorkload($request, 'any');
        if ($workload === null) {
            return response()->json(['message' => 'Cluster application not found.'], 404);
        }
        $this->authorize('update', $workload);
        $node = $workload->nodes()
            ->when($request->filled('server_uuid'), fn ($query) => $query->where('nodes.uuid', (string) $request->query('server_uuid')))
            ->orderBy('nodes.id')
            ->first();
        if ($node === null) {
            return response()->json(['message' => 'Server not found.'], 404);
        }

        return $this->runClusterAction(function () use ($request, $workload, $node): JsonResponse {
            $result = FetchContainerLogs::run($node, $workload, (int) $request->query('lines', 100));

            return response()->json(['logs' => $result['logs'], 'truncated' => $result['truncated']]);
        });
    }

    /** @return array<string, mixed> */
    private function portResponse(NodePublishedPort $port): array
    {
        return [
            'uuid' => $port->uuid,
            'protocol' => $port->protocol,
            'container_port' => $port->container_port,
            'host_port' => $port->host_port,
            'source_cidrs' => array_values($port->source_cidrs ?? []),
            'label' => PublishNodeWorkloadPort::label($port->spec()),
        ];
    }
}
