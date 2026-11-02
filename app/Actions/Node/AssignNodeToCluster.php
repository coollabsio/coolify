<?php

namespace App\Actions\Node;

use App\Jobs\ReconcileNodeClusterNetworkJob;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\User;
use App\Rules\PrivateIpv4Cidr;
use DomainException;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

class AssignNodeToCluster
{
    use AsAction;

    public function handle(NodeCluster $cluster, Node $node, ?User $user = null): Node
    {
        if ($cluster->team_id !== $node->team_id) {
            throw new DomainException('The server and cluster must belong to the same team.');
        }

        $wasActivated = $cluster->hasActivatedNetwork();
        // Resolved before the row locks: a DNS lookup must not hold them.
        $knownAddresses = $this->knownAddresses($node);
        $node = DB::transaction(function () use ($cluster, $node, $knownAddresses): Node {
            $cluster = NodeCluster::query()->lockForUpdate()->findOrFail($cluster->id);
            $node = Node::query()->lockForUpdate()->findOrFail($node->id);
            if ($node->node_cluster_id === $cluster->id && $node->wireguard_ip && $node->workload_cidr) {
                return $node;
            }
            if ($node->node_cluster_id !== null && $node->node_cluster_id !== $cluster->id) {
                throw new DomainException('The server already belongs to another cluster. Remove it before assigning it again.');
            }
            $ip = $node->wireguard_ip;
            if ($node->node_cluster_id === null) {
                $containedAddress = PrivateIpv4Cidr::firstContainedAddress($cluster->cidr, $knownAddresses);
                if ($containedAddress !== null) {
                    throw new DomainException("The server address {$containedAddress} is inside the cluster CIDR {$cluster->cidr}. The cluster firewall drops host traffic to this range, which can cut SSH and DNS. Use a cluster range that does not include server, LAN, VPC, or DNS resolver addresses.");
                }
                if ($cluster->nodes()->count() >= 100) {
                    throw new DomainException('A full-mesh cluster supports at most 100 servers.');
                }
                [$network, $prefix] = explode('/', $cluster->cidr);
                $base = (int) sprintf('%u', ip2long($network));
                $lastHostOffset = (2 ** (32 - (int) $prefix)) - 2;
                $used = $cluster->nodes()->pluck('wireguard_ip')->all();
                $ip = null;
                for ($i = 2; $i <= $lastHostOffset; $i++) {
                    $candidate = long2ip($base + $i);
                    if (! in_array($candidate, $used, true)) {
                        $ip = $candidate;
                        break;
                    }
                }
                if (! $ip) {
                    throw new DomainException('No server address is available in this cluster.');
                }
            }
            $workloadCidr = $node->workload_cidr ?? $this->nextWorkloadCidr($cluster);
            // A cluster serves domains from the start: the first server that joins without an ingress server turns ingress on.
            $isIngress = $node->is_ingress || ($node->node_cluster_id === null
                && $node->supportsCapability(ReconcileNodeClusterNetwork::INGRESS_CAPABILITY) !== false
                && ! $cluster->nodes()->where('is_ingress', true)->exists());
            $node->update(['node_cluster_id' => $cluster->id, 'wireguard_ip' => $ip, 'workload_cidr' => $workloadCidr, 'is_ingress' => $isIngress, 'network_applied_revision' => null]);
            $cluster->increment('desired_revision');

            return $node;
        });

        if ($wasActivated && $user !== null) {
            $cluster->update(['network_status' => 'reconciling']);
            ReconcileNodeClusterNetworkJob::dispatch($cluster->id, $user->id)->afterCommit();
        }

        return $node;
    }

    /**
     * IPv4 addresses that Coolify knows for the Node: its SSH address (and the addresses it resolves to when
     * it is a hostname), its WireGuard endpoint, and IPv4 literals in its metadata. Sentinel does not report
     * interface addresses or DNS resolvers yet, so LAN and VPC addresses that are not stored are not checked.
     *
     * @return list<string>
     */
    private function knownAddresses(Node $node): array
    {
        $addresses = [$node->ip];
        if (filled($node->ip) && filter_var($node->ip, FILTER_VALIDATE_IP) === false) {
            $addresses = [...$addresses, ...(gethostbynamel($node->ip) ?: [])];
        }
        if (filled($node->wireguard_endpoint)) {
            $addresses[] = preg_replace('/:\d+$/', '', (string) $node->wireguard_endpoint);
        }
        $metadata = is_array($node->metadata) ? $node->metadata : [];
        array_walk_recursive($metadata, function (mixed $value) use (&$addresses): void {
            if (is_string($value)) {
                $addresses[] = $value;
            }
        });

        return array_values(array_filter($addresses, fn (?string $address): bool => filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false));
    }

    /**
     * Gives each Node of the cluster a /24 from 198.18.0.0/15 (RFC 2544). Hosts, VPCs, Tailscale (100.64.0.0/10),
     * Docker, and Podman do not use this range by default, and the cluster CIDR is always RFC 1918. The cluster
     * row is locked by the caller, so concurrent assignments to the same cluster cannot pick the same subnet.
     */
    private function nextWorkloadCidr(NodeCluster $cluster): string
    {
        $used = $cluster->nodes()->whereNotNull('workload_cidr')->pluck('workload_cidr')->all();
        for ($index = 0; $index < 512; $index++) {
            $secondOctet = 18 + intdiv($index, 256);
            $thirdOctet = $index % 256;
            $candidate = "198.{$secondOctet}.{$thirdOctet}.0/24";
            if (! in_array($candidate, $used, true)) {
                return $candidate;
            }
        }

        throw new DomainException('No workload subnet is available.');
    }
}
