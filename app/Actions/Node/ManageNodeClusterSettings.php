<?php

namespace App\Actions\Node;

use App\Enums\NodeImageStorage;
use App\Jobs\ReconcileNodeClusterNetworkJob;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\NodeFirewallRule;
use App\Models\NodeWorkload;
use App\Models\RegistryCredential;
use App\Models\User;
use App\Rules\PrivateIpv4Cidr;
use App\Services\DockerRegistryLogins;
use App\Support\ValidationPatterns;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * The settings of a cluster, for the cluster pages and the API (decision 0029): the name and the
 * network, the deployment pressure policy, the image storage, and the firewall rules. Only a
 * team admin may change them (`update` on the cluster). The errors use the
 * snake_case field names.
 */
class ManageNodeClusterSettings
{
    use AsAction;

    /**
     * @param  array{name: string, description: ?string, cidr: string, wireguard_interface: string, wireguard_port: int}  $input
     *
     * @throws ValidationException
     */
    public function updateSettings(NodeCluster $cluster, array $input, User $user): NodeCluster
    {
        Gate::forUser($user)->authorize('update', $cluster);
        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255', Rule::unique('node_clusters')->where('team_id', $cluster->team_id)->ignore($cluster->id)],
            'description' => ['nullable', 'string', 'max:1000'],
            'cidr' => ['required', new PrivateIpv4Cidr],
            'wireguard_interface' => ['required', 'regex:/^[a-zA-Z0-9_.-]{1,15}$/'],
            'wireguard_port' => ['required', 'integer', 'between:1,65535'],
        ])->validate();
        if ($cluster->hasActivatedNetwork()) {
            foreach (['cidr', 'wireguard_interface', 'wireguard_port'] as $field) {
                if ($validated[$field] !== $cluster->{$field}) {
                    throw ValidationException::withMessages([$field => 'This network setting cannot be changed on an active cluster.']);
                }
            }
        }
        if ($validated['cidr'] !== $cluster->cidr && $cluster->nodes()->exists()) {
            throw ValidationException::withMessages(['cidr' => 'Remove all servers from this cluster before changing its CIDR.']);
        }
        try {
            return UpdateNodeCluster::run($cluster, $validated['name'], blank($validated['description']) ? null : $validated['description'], $validated['cidr'], $validated['wireguard_interface'], (int) $validated['wireguard_port']);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['cidr' => $exception->getMessage()]);
        }
    }

    /**
     * @param  array{cpu_pressure_threshold: int, memory_pressure_threshold: int, disk_pressure_threshold: int, resource_stale_after_minutes: int}  $input
     *
     * @throws ValidationException
     */
    public function updatePressurePolicy(NodeCluster $cluster, array $input, User $user): NodeCluster
    {
        Gate::forUser($user)->authorize('update', $cluster);
        $validated = Validator::make($input, [
            'cpu_pressure_threshold' => ['required', 'integer', 'between:1,100'],
            'memory_pressure_threshold' => ['required', 'integer', 'between:1,100'],
            'disk_pressure_threshold' => ['required', 'integer', 'between:1,100'],
            'resource_stale_after_minutes' => ['required', 'integer', 'between:1,60'],
        ])->validate();
        $cluster->update($validated);

        return $cluster->refresh();
    }

    /**
     * Saves where the builds of the cluster are stored: on its servers (decision 0030) or in an
     * external registry. Images in the previous storage are not copied. The registry credential
     * must be of the team of the cluster and of the registry of the repository.
     *
     * @return bool True when the storage changed from an earlier choice.
     *
     * @throws ValidationException `image_storage`, `image_repository`, or `image_registry_credential_uuid`.
     */
    public function updateImageStorage(NodeCluster $cluster, ?string $storage, ?string $repository, ?string $credentialUuid, User $user): bool
    {
        Gate::forUser($user)->authorize('update', $cluster);
        $external = $storage === NodeImageStorage::EXTERNAL->value;
        Validator::make([
            'image_storage' => $storage,
            'image_repository' => $repository,
            'image_registry_credential_uuid' => $credentialUuid,
        ], [
            'image_storage' => ['required', Rule::in([NodeImageStorage::EXTERNAL->value, NodeImageStorage::LOCAL->value])],
            'image_repository' => $external
                ? [...ValidationPatterns::dockerImageNameRules(required: true), 'not_regex:/@/', 'not_regex:/:[^\/]*$/', 'not_regex:/^localhost\//']
                : ['nullable'],
            'image_registry_credential_uuid' => ['nullable', 'string'],
        ], [
            'image_repository.not_regex' => 'Enter a repository without a tag or digest, outside the cluster, for example ghcr.io/acme/coolify-builds.',
        ], [
            'image_storage' => 'image storage',
            'image_repository' => 'repository',
            'image_registry_credential_uuid' => 'registry credential',
        ])->validate();
        $repository = $external ? trim((string) $repository) : null;
        $credential = null;
        if ($external && filled($credentialUuid)) {
            $credential = RegistryCredential::query()->where('team_id', $cluster->team_id)->where('uuid', $credentialUuid)->first();
            if ($credential === null) {
                throw ValidationException::withMessages(['image_registry_credential_uuid' => 'Select a registry credential of this team.']);
            }
            if (! $credential->matchesImage($repository)) {
                throw ValidationException::withMessages(['image_registry_credential_uuid' => "This credential is for {$credential->registry}, but the repository is in ".DockerRegistryLogins::registryFromImage($repository).'.']);
            }
        }
        $previous = $cluster->image_storage;
        $cluster->update([
            'image_storage' => $storage,
            'image_repository' => $repository,
            'image_registry_credential_id' => $credential?->id,
        ]);

        return $previous !== null && $previous !== $cluster->image_storage;
    }

    /**
     * Adds a rule that lets a workload or a server of the mesh reach a workload. An existing rule
     * is not added again.
     *
     * @return array{created: bool, rule: NodeFirewallRule}
     *
     * @throws ValidationException `source_type`, `source`, `destination`, `protocol`, or `port`.
     */
    public function addFirewallRule(NodeCluster $cluster, string $sourceType, string $sourceUuid, string $destinationUuid, string $protocol, int $port, User $user): array
    {
        Gate::forUser($user)->authorize('update', $cluster);
        $validated = Validator::make([
            'source_type' => $sourceType,
            'source' => $sourceUuid,
            'destination' => $destinationUuid,
            'protocol' => $protocol,
            'port' => $protocol === 'icmp' ? 0 : $port,
        ], [
            'source_type' => ['required', Rule::in(['workload', 'node'])],
            'source' => ['required', 'string'],
            'destination' => ['required', 'string'],
            'protocol' => ['required', Rule::in(['tcp', 'udp', 'icmp'])],
            'port' => $protocol === 'icmp' ? ['required', 'integer', 'in:0'] : ['required', 'integer', 'between:1,65535'],
        ])->validate();

        $sourceWorkload = $sourceType === 'workload' ? self::meshWorkloads($cluster)->where('uuid', $sourceUuid)->first() : null;
        $sourceNode = $sourceType === 'node'
            ? Node::query()->where('team_id', $cluster->team_id)->where('node_cluster_id', $cluster->id)->where('uuid', $sourceUuid)->first()
            : null;
        $destination = self::meshWorkloads($cluster)->where('uuid', $destinationUuid)->first();
        $errors = [];
        if ($sourceWorkload === null && $sourceNode === null) {
            $errors['source'] = 'Select a workload or server from this mesh.';
        }
        if ($destination === null) {
            $errors['destination'] = 'Select a workload from this mesh.';
        } elseif ($sourceWorkload?->id === $destination->id) {
            $errors['destination'] = 'The source and destination must be different applications.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $result = DB::transaction(function () use ($cluster, $validated, $sourceWorkload, $sourceNode, $destination): array {
            $rule = NodeFirewallRule::query()->firstOrCreate([
                'node_cluster_id' => $cluster->id,
                'source_workload_id' => $sourceWorkload?->id,
                'source_node_id' => $sourceNode?->id,
                'destination_workload_id' => $destination->id,
                'protocol' => $validated['protocol'],
                'port' => $validated['port'],
            ]);
            if ($rule->wasRecentlyCreated) {
                $cluster->increment('desired_revision');
            }

            return ['created' => $rule->wasRecentlyCreated, 'rule' => $rule];
        });
        if ($result['created']) {
            self::queueNetworkReconciliation($cluster, $user);
        }

        return $result;
    }

    public function removeFirewallRule(NodeCluster $cluster, NodeFirewallRule $rule, User $user): void
    {
        Gate::forUser($user)->authorize('update', $cluster);
        DB::transaction(function () use ($cluster, $rule): void {
            NodeFirewallRule::query()->where('node_cluster_id', $cluster->id)->whereKey($rule->id)->firstOrFail()->delete();
            $cluster->increment('desired_revision');
        });
        self::queueNetworkReconciliation($cluster, $user);
    }

    /**
     * The workloads that run in the mesh of the cluster. A preview gets the outbound rules of its
     * parent (decision 0025), so it is not a rule endpoint or a listed resource.
     *
     * @return Builder<NodeWorkload>
     */
    public static function meshWorkloads(NodeCluster $cluster): Builder
    {
        return NodeWorkload::query()
            ->where('team_id', $cluster->team_id)
            ->whereNull('preview_of_id')
            ->whereHas('nodes', fn ($query) => $query->where('nodes.node_cluster_id', $cluster->id));
    }

    private static function queueNetworkReconciliation(NodeCluster $cluster, User $user): void
    {
        $cluster->refresh()->update(['network_status' => 'reconciling']);
        ReconcileNodeClusterNetworkJob::dispatch($cluster->id, $user->id);
    }
}
