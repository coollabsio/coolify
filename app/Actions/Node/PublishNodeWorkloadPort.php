<?php

namespace App\Actions\Node;

use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\NodePublishedPort;
use App\Models\NodeWorkload;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Publishes a TCP or UDP port of a cluster workload on every Node of its cluster (decision 0016).
 * The port is network intent: the next network revision sends it to Sentinel, without a redeploy.
 */
class PublishNodeWorkloadPort
{
    use AsAction;

    public const PROTOCOLS = ['tcp', 'udp'];

    /** Sentinel refuses a longer allow-list. */
    public const MAX_SOURCE_CIDRS = 64;

    /**
     * @param  array{protocol?: mixed, host_port?: mixed, container_port?: mixed, source_cidrs?: mixed}  $port  `source_cidrs` is a list or text with one CIDR on each line.
     *
     * @throws ValidationException with the keys `protocol`, `host_port`, `container_port`, and `source_cidrs`.
     */
    public function handle(NodeWorkload $workload, array $port, User $user): NodePublishedPort
    {
        Gate::forUser($user)->authorize('update', $workload);
        $cluster = $workload->cluster()
            ?? throw ValidationException::withMessages(['container_port' => 'Place the application on a server of a cluster first.']);
        $spec = self::validated($port);
        self::ensureMayOpenOnCluster($cluster, $user);

        $published = self::lockCluster($cluster, function () use ($cluster, $workload, $spec): NodePublishedPort {
            $reason = self::unavailableReason($cluster, $spec);
            if ($reason !== null) {
                throw ValidationException::withMessages(['host_port' => $reason]);
            }
            try {
                return self::create($cluster, $workload, $spec);
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['host_port' => self::label($spec).' is already published in this cluster.']);
            }
        });
        QueueNodeClusterNetworkRevision::run($cluster, $user);

        return $published;
    }

    /**
     * A published port opens on every server of the cluster, so the user needs placeResource on each of them:
     * team-wide write, or write on a resource that runs there.
     */
    public static function ensureMayOpenOnCluster(NodeCluster $cluster, User $user): void
    {
        $denied = Node::query()
            ->where('node_cluster_id', $cluster->id)
            ->orderBy('name')
            ->get()
            ->reject(fn (Node $node): bool => Gate::forUser($user)->allows('placeResource', $node))
            ->pluck('name');
        if ($denied->isNotEmpty()) {
            throw ValidationException::withMessages(['host_port' => 'A published port opens on every server of the cluster, so it needs write access on the whole team or on a resource on each server. You have none on: '.$denied->implode(', ').'.']);
        }
    }

    /**
     * Replaces every published port of a stack service with the ports of its Compose file. The
     * caller checks them with unavailableReason() first and queues the network revision.
     *
     * @param  list<array{protocol: string, host_port: int, container_port: int, source_cidrs: list<string>}>  $specs
     */
    public static function replace(NodeCluster $cluster, NodeWorkload $workload, array $specs): void
    {
        $workload->publishedPorts()->delete();
        foreach ($specs as $spec) {
            self::create($cluster, $workload, $spec);
        }
    }

    /**
     * Runs the callback in a transaction while no other writer changes the published ports of the
     * cluster, so two workloads cannot take the same port at the same time.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function lockCluster(NodeCluster $cluster, callable $callback): mixed
    {
        return Cache::lock("node-published-ports:{$cluster->id}", 30)->block(10, fn () => DB::transaction($callback));
    }

    /**
     * Why the cluster cannot publish the port, or null when it can: a Node of the cluster uses the
     * host port, or another workload publishes it.
     *
     * @param  array{protocol: string, host_port: int}  $spec
     * @param  list<int>  $replacedWorkloadIds  Workloads whose ports the caller replaces, so their ports are free.
     * @param  array<int, string>|null  $reservedPorts  NodeCluster::reservedHostPorts(), when the caller checks several ports.
     */
    public static function unavailableReason(NodeCluster $cluster, array $spec, array $replacedWorkloadIds = [], ?array $reservedPorts = null): ?string
    {
        $reserved = ($reservedPorts ?? $cluster->reservedHostPorts())[$spec['host_port']] ?? null;
        if ($reserved !== null) {
            return self::label($spec)." is reserved for {$reserved}.";
        }
        $taken = NodePublishedPort::query()
            ->with('workload:id,name')
            ->where('node_cluster_id', $cluster->id)
            ->where('protocol', $spec['protocol'])
            ->where('host_port', $spec['host_port'])
            ->whereNotIn('node_workload_id', $replacedWorkloadIds)
            ->first();

        return $taken === null ? null : self::label($spec).' is already published by '.($taken->workload->name ?? 'another application').'.';
    }

    /** `TCP 5432`. */
    public static function label(array $spec): string
    {
        return strtoupper($spec['protocol']).' '.$spec['host_port'];
    }

    /**
     * The port in its stored shape. The host port defaults to the container port.
     *
     * @param  array{protocol?: mixed, host_port?: mixed, container_port?: mixed, source_cidrs?: mixed}  $port
     * @return array{protocol: string, host_port: int, container_port: int, source_cidrs: list<string>}
     *
     * @throws ValidationException
     */
    public static function validated(array $port): array
    {
        $containerPort = trim((string) ($port['container_port'] ?? ''));
        $hostPort = trim((string) ($port['host_port'] ?? ''));
        $sources = $port['source_cidrs'] ?? [];
        $sources = is_array($sources) ? $sources : preg_split('/[\s,]+/', (string) $sources);
        $sources = array_values(array_filter(array_map(fn ($source): string => trim((string) $source), $sources), fn (string $source): bool => $source !== ''));
        $input = [
            'protocol' => strtolower(trim((string) ($port['protocol'] ?? 'tcp'))),
            'container_port' => $containerPort,
            'host_port' => $hostPort === '' ? $containerPort : $hostPort,
            'source_cidrs' => $sources,
        ];
        $portMessage = 'Enter a port between 1 and 65535.';
        $validator = Validator::make($input, [
            'protocol' => ['required', 'in:'.implode(',', self::PROTOCOLS)],
            'container_port' => ['required', 'integer', 'between:1,65535'],
            'host_port' => ['required', 'integer', 'between:1,65535'],
            'source_cidrs' => ['array', 'max:'.self::MAX_SOURCE_CIDRS],
        ], [
            'protocol.*' => 'Select TCP or UDP.',
            'container_port.*' => $portMessage,
            'host_port.*' => $portMessage,
            'source_cidrs.max' => 'Use at most '.self::MAX_SOURCE_CIDRS.' source addresses.',
        ]);
        $validator->after(function ($validator) use ($sources): void {
            $invalid = array_values(array_filter($sources, fn (string $source): bool => self::normalizedCidr($source) === null));
            if ($invalid !== []) {
                $validator->errors()->add('source_cidrs', 'Not an IPv4 address or CIDR: '.implode(', ', array_slice($invalid, 0, 5)).'.');
            }
        });
        $validator->validate();

        return [
            'protocol' => $input['protocol'],
            'host_port' => (int) $input['host_port'],
            'container_port' => (int) $input['container_port'],
            'source_cidrs' => array_values(array_unique(array_map(fn (string $source): string => (string) self::normalizedCidr($source), $sources))),
        ];
    }

    /** `10.0.0.5/24` becomes `10.0.0.0/24`, and `10.0.0.5` becomes `10.0.0.5/32`. Null when it is not an IPv4 address or CIDR. */
    public static function normalizedCidr(string $source): ?string
    {
        [$address, $prefix] = str_contains($source, '/') ? explode('/', $source, 2) : [$source, '32'];
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || preg_match('/\A(?:[0-9]|[12][0-9]|3[0-2])\z/', $prefix) !== 1) {
            return null;
        }
        $mask = (int) $prefix === 0 ? 0 : (~0 << (32 - (int) $prefix)) & 0xFFFFFFFF;

        return long2ip(ip2long($address) & $mask).'/'.$prefix;
    }

    /** @param  array{protocol: string, host_port: int, container_port: int, source_cidrs: list<string>}  $spec */
    private static function create(NodeCluster $cluster, NodeWorkload $workload, array $spec): NodePublishedPort
    {
        return $workload->publishedPorts()->create([
            ...$spec,
            'node_cluster_id' => $cluster->id,
            'source_cidrs' => $spec['source_cidrs'] === [] ? null : $spec['source_cidrs'],
        ]);
    }
}
