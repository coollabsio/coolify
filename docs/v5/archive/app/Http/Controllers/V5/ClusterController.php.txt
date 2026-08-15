<?php

namespace App\Http\Controllers\V5;

use App\Http\Controllers\Controller;
use App\Http\Controllers\V5\Concerns\ResolvesCurrentTeam;
use App\Http\Controllers\V5\Concerns\ResolvesProjectSelection;
use App\Http\Controllers\V5\Concerns\ValidatesBuilderConfiguration;
use App\Models\PrivateKey;
use App\Models\Team;
use App\Models\V5\Cluster as V5Cluster;
use App\Services\Flux\FluxHealth;
use App\Support\V5\ClusterSerializer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ClusterController extends Controller
{
    use ResolvesCurrentTeam;
    use ResolvesProjectSelection;
    use ValidatesBuilderConfiguration;

    public function index(Request $request, FluxHealth $fluxHealth): Response
    {
        $currentTeam = $request->attributes->get('v5.currentTeam');
        $projects = $this->projects($currentTeam);
        [$selectedProject, $selectedEnvironment] = $this->selectedProjectAndEnvironment($request, $projects);

        return Inertia::render('Clusters', [
            'currentTeam' => $this->serializeCurrentTeam($currentTeam),
            'flux' => $fluxHealth->check(),
            'clusters' => $this->clusters($currentTeam),
            'privateKeys' => $this->privateKeys($currentTeam),
            'projects' => $projects,
            'selectedProjectUuid' => $selectedProject['uuid'] ?? null,
            'selectedEnvironmentUuid' => $selectedEnvironment['uuid'] ?? null,
        ]);
    }

    public function show(Request $request, V5Cluster $cluster): JsonResponse
    {
        $currentTeam = $this->currentTeamOrFail($request);
        $this->authorize('view', [$cluster, $currentTeam]);

        return response()->json([
            'cluster' => app(ClusterSerializer::class)->serializeFresh($cluster),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $currentTeam = $this->currentTeamOrFail($request);
        $this->authorize('create', [V5Cluster::class, $currentTeam]);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('v5_clusters', 'name')->where('team_id', $currentTeam->id),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'wireguard_interface' => ['sometimes', 'string', 'max:32', 'regex:/^[a-zA-Z0-9_.-]+$/'],
            'wireguard_management_pool' => ['sometimes', 'string', 'max:64', $this->ipv4CidrRule()],
            'wireguard_listen_port' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'container_network_pool' => ['sometimes', 'string', 'max:64', $this->ipv4CidrRule()],
            'container_network_prefix' => ['sometimes', 'integer', 'min:1', 'max:32'],
            'namespaces' => ['sometimes', 'array', 'min:1'],
            'namespaces.*' => ['string', 'distinct', 'regex:/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/'],
            'default_deny_containers' => ['sometimes', 'boolean'],
            'coold_version' => ['sometimes', 'string', 'max:64'],
            'corrosion_version' => ['sometimes', 'string', 'max:64'],
            'corrosion_gossip_port' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'corrosion_api_port' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'builder_enabled' => ['sometimes', 'boolean'],
            'builder_capacity' => $this->builderCapacityRules(
                $this->requestedBuilderEnabled($request, true)
            ),
            'builder_cpu_quota' => ['sometimes', 'string', 'max:32'],
            'builder_memory_max' => ['sometimes', 'string', 'max:32'],
            'builder_timeout_secs' => ['sometimes', 'integer', 'min:1', 'max:86400'],
        ]);

        $cluster = V5Cluster::query()->create([
            ...$this->defaultClusterConfiguration(),
            ...collect($validated)->except(['name', 'description'])->all(),
            'team_id' => $currentTeam->id,
            'created_by_user_id' => $request->user()->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
        ]);

        return response()->json([
            'cluster' => app(ClusterSerializer::class)->serializeFresh($cluster),
        ], 201);
    }

    public function destroy(Request $request, V5Cluster $cluster): \Illuminate\Http\Response|JsonResponse
    {
        $currentTeam = $this->currentTeamOrFail($request);
        $this->authorize('delete', [$cluster, $currentTeam]);

        if ($cluster->servers()->exists()) {
            return response()->json([
                'message' => 'Only empty clusters can be deleted.',
            ], 422);
        }

        $cluster->delete();

        return response()->noContent();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function clusters(mixed $currentTeam): array
    {
        if (! $currentTeam instanceof Team) {
            return [];
        }

        $serializer = app(ClusterSerializer::class);

        return V5Cluster::query()
            ->where('team_id', $currentTeam->id)
            ->with(['servers' => fn ($query) => $query
                ->with('privateKey')
                ->orderBy('name')])
            ->withCount('servers')
            ->orderBy('name')
            ->get()
            ->map(fn (V5Cluster $cluster) => $serializer->serialize($cluster))
            ->all();
    }

    /**
     * @return array<int, array{id: string, name: string}>
     */
    private function privateKeys(mixed $currentTeam): array
    {
        if (! $currentTeam instanceof Team) {
            return [];
        }

        return PrivateKey::query()
            ->where('team_id', $currentTeam->id)
            ->where('is_git_related', false)
            ->orderBy('name')
            ->get(['id', 'uuid', 'name'])
            ->map(fn (PrivateKey $privateKey) => [
                'id' => $privateKey->uuid,
                'name' => $privateKey->name,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultClusterConfiguration(): array
    {
        return [
            'wireguard_interface' => V5Cluster::DEFAULT_WIREGUARD_INTERFACE,
            'wireguard_management_pool' => V5Cluster::DEFAULT_WIREGUARD_MANAGEMENT_POOL,
            'wireguard_listen_port' => V5Cluster::DEFAULT_WIREGUARD_LISTEN_PORT,
            'container_network_pool' => V5Cluster::DEFAULT_CONTAINER_NETWORK_POOL,
            'container_network_prefix' => V5Cluster::DEFAULT_CONTAINER_NETWORK_PREFIX,
            'namespaces' => V5Cluster::DEFAULT_NAMESPACES,
            'default_deny_containers' => true,
            'coold_version' => V5Cluster::DEFAULT_COOLD_VERSION,
            'corrosion_version' => V5Cluster::DEFAULT_CORROSION_VERSION,
            'corrosion_gossip_port' => V5Cluster::DEFAULT_CORROSION_GOSSIP_PORT,
            'corrosion_api_port' => V5Cluster::DEFAULT_CORROSION_API_PORT,
            'builder_enabled' => true,
            'builder_capacity' => V5Cluster::DEFAULT_BUILDER_CAPACITY,
            'builder_cpu_quota' => V5Cluster::DEFAULT_BUILDER_CPU_QUOTA,
            'builder_memory_max' => V5Cluster::DEFAULT_BUILDER_MEMORY_MAX,
            'builder_timeout_secs' => V5Cluster::DEFAULT_BUILDER_TIMEOUT_SECS,
        ];
    }

    private function ipv4CidrRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_string($value) || ! str_contains($value, '/')) {
                $fail('The :attribute must be a valid IPv4 CIDR range.');

                return;
            }

            [$ip, $prefix] = explode('/', $value, 2);

            if (
                filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
                || ! ctype_digit($prefix)
                || (int) $prefix < 0
                || (int) $prefix > 32
            ) {
                $fail('The :attribute must be a valid IPv4 CIDR range.');
            }
        };
    }
}
