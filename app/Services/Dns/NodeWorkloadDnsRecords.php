<?php

namespace App\Services\Dns;

use App\Exceptions\DnsRecordConflictException;
use App\Models\DnsProviderZone;
use App\Models\ManagedDnsRecord;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\NodeWorkload;
use App\Support\DnsRecordHints;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Managed DNS records of the domains of a cluster application (decision 0019). Each hostname gets one A or AAAA
 * record per public address of the ingress Nodes of the workload's cluster (round-robin), in the one zone of the
 * workload's team that manages it. Every record has a reference to the workload, and only records that Coolify
 * created are changed or deleted (the v4 owner rule).
 */
class NodeWorkloadDnsRecords
{
    public const PROBLEMS_TTL_SECONDS = 7 * 24 * 3600;

    public function __construct(
        private CloudflareDnsProvider $provider,
        private ManagedDnsRecordCleanup $cleanup,
    ) {}

    /**
     * Converges the records of every workload of the cluster: the workloads that run on its Nodes and the
     * services of its stacks.
     */
    public function syncCluster(NodeCluster $cluster): void
    {
        NodeWorkload::query()->withoutGlobalScopes()
            ->where('team_id', $cluster->team_id)
            ->where(fn ($query) => $query
                ->whereHas('nodes', fn ($query) => $query->where('nodes.node_cluster_id', $cluster->id))
                ->orWhereHas('stack', fn ($query) => $query->where('node_cluster_id', $cluster->id)))
            ->orderBy('id')
            ->get()
            ->each(fn (NodeWorkload $workload) => $this->sync($workload));
    }

    /**
     * Releases the records of removed hostnames and of addresses that are no longer ingress addresses, then creates
     * the missing records of each hostname whose zone has automatic DNS on. A workload without a cluster (not
     * placed yet, or between the steps of a move) keeps the records of its current hostnames. Never throws; the
     * problem of each hostname is kept for the Domains page.
     */
    public function sync(NodeWorkload $workload): void
    {
        $hostnames = $workload->dnsHostnames();
        $hasCluster = $workload->cluster() !== null;
        $addresses = $this->ingressAddresses($workload);
        $problems = [];

        foreach ($this->records($workload) as $record) {
            $removed = ! in_array($record->name, $hostnames, true);
            $stale = $hasCluster && ! $this->containsAddress($addresses, $record->content);
            if (! $removed && ! $stale) {
                continue;
            }
            try {
                if ($this->cleanup->release($record, [[$workload->getMorphClass(), $workload->getKey()]], true)?->shouldRetry() && ! $removed) {
                    $problems[$record->name] = "The old record to {$record->content} could not be deleted. Coolify tries again with the next change.";
                }
            } catch (Throwable $exception) {
                if (! $removed) {
                    $problems[$record->name] = $exception->getMessage();
                }
            }
        }

        foreach ($hostnames as $hostname) {
            $zones = $this->zones($workload, $hostname);
            if ($zones->count() !== 1 || ! $zones->first()->integrationToken->automaticDnsEnabled()) {
                continue;
            }
            $problem = $this->createMissing($workload, $hostname, $zones->first(), $addresses);
            if ($problem !== null) {
                $problems[$hostname] ??= $problem;
            }
        }

        Cache::put(self::problemsKey($workload), $problems, self::PROBLEMS_TTL_SECONDS);
    }

    /**
     * Creates the missing records of one hostname in the zone (the Create records action of the Domains page).
     *
     * @return string|null the problem, null when every ingress address has a record
     */
    public function create(NodeWorkload $workload, string $hostname, DnsProviderZone $zone): ?string
    {
        $problem = $this->createMissing($workload, $hostname, $zone, $this->ingressAddresses($workload));
        $problems = Cache::get(self::problemsKey($workload), []);
        if ($problem === null) {
            unset($problems[$hostname]);
        } else {
            $problems[$hostname] = $problem;
        }
        Cache::put(self::problemsKey($workload), $problems, self::PROBLEMS_TTL_SECONDS);

        return $problem;
    }

    /**
     * The DNS state of each hostname, for the Domains page. Reads only the database.
     *
     * @return array{addresses: list<string>, hostnames: list<array{hostname: string, zones: list<array{id: int, zone: string, credential: string, automatic: bool}>, records: list<array{type: string, content: string, owned: bool}>, missing: list<string>, problem: ?string}>}
     */
    public function status(NodeWorkload $workload): array
    {
        $addresses = $this->ingressAddresses($workload);
        $records = $this->records($workload)->groupBy('name');
        $problems = Cache::get(self::problemsKey($workload), []);

        return [
            'addresses' => $addresses,
            'hostnames' => collect($workload->dnsHostnames())->map(function (string $hostname) use ($workload, $addresses, $records, $problems): array {
                $hostnameRecords = $records->get($hostname, collect());

                return [
                    'hostname' => $hostname,
                    'zones' => $this->zones($workload, $hostname)->map(fn (DnsProviderZone $zone): array => [
                        'id' => $zone->id,
                        'zone' => $zone->name,
                        'credential' => $zone->integrationToken->name,
                        'automatic' => $zone->integrationToken->automaticDnsEnabled(),
                    ])->values()->all(),
                    'records' => $hostnameRecords->map(fn (ManagedDnsRecord $record): array => [
                        'type' => $record->type, 'content' => $record->content, 'owned' => $record->owned,
                    ])->values()->all(),
                    'missing' => array_values(array_filter($addresses, fn (string $address): bool => ! $hostnameRecords
                        ->contains(fn (ManagedDnsRecord $record): bool => DnsRecordHints::sameAddress($record->content, $address)))),
                    'problem' => is_string($problems[$hostname] ?? null) ? $problems[$hostname] : null,
                ];
            })->all(),
        ];
    }

    /**
     * Public addresses of the ingress Nodes of the workload's cluster, with the v4 rule: private, reserved, CGNAT,
     * and link-local addresses are left out. An offline ingress Node keeps its address (no failover).
     *
     * @return list<string>
     */
    public function ingressAddresses(NodeWorkload $workload): array
    {
        $cluster = $workload->cluster();
        if ($cluster === null) {
            return [];
        }

        return Node::query()
            ->where('node_cluster_id', $cluster->id)
            ->where('team_id', $workload->team_id)
            ->where('is_ingress', true)
            ->orderBy('id')
            ->get()
            ->flatMap(fn (Node $node): array => ServerDnsAddresses::forNode($node)->publicAddresses())
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The zones of the workload's team that manage the hostname. A generated sslip.io domain has no zone.
     *
     * @return Collection<int, DnsProviderZone>
     */
    public function zones(NodeWorkload $workload, string $hostname): Collection
    {
        if (str_ends_with($hostname, '.sslip.io')) {
            return collect();
        }

        return $this->provider->findZones((int) $workload->team_id, $hostname);
    }

    public static function problemsKey(NodeWorkload $workload): string
    {
        return "node-workload-dns-problems:{$workload->id}";
    }

    /** @param  list<string>  $addresses */
    private function createMissing(NodeWorkload $workload, string $hostname, DnsProviderZone $zone, array $addresses): ?string
    {
        if ($addresses === []) {
            return 'No ingress server of the cluster has a public address. Turn on ingress for a server with a public address.';
        }

        $records = $this->records($workload)->where('name', $hostname);
        foreach ($addresses as $address) {
            if ($records->contains(fn (ManagedDnsRecord $record): bool => DnsRecordHints::sameAddress($record->content, $address))) {
                continue;
            }
            try {
                $this->provider->createRecord($zone, $hostname, $address, $workload, proxied: false, roundRobin: true);
            } catch (DnsRecordConflictException $conflict) {
                return "A DNS record that Coolify did not create points {$hostname} to {$conflict->currentValue}. Coolify does not change it. Delete it in Cloudflare, then create the records again.";
            } catch (LockTimeoutException) {
                return "Another DNS change for {$hostname} is in progress. Try again in a few seconds.";
            } catch (Throwable $exception) {
                return $exception->getMessage();
            }
        }

        return null;
    }

    /** @return Collection<int, ManagedDnsRecord> */
    private function records(NodeWorkload $workload): Collection
    {
        return ManagedDnsRecord::query()
            ->where('team_id', $workload->team_id)
            ->whereHas('references', fn ($query) => $query
                ->where('resource_type', $workload->getMorphClass())
                ->where('resource_id', $workload->getKey()))
            ->orderBy('name')
            ->orderBy('type')
            ->orderBy('id')
            ->get();
    }

    /** @param  list<string>  $addresses */
    private function containsAddress(array $addresses, string $content): bool
    {
        return collect($addresses)->contains(fn (string $address): bool => DnsRecordHints::sameAddress($address, $content));
    }
}
